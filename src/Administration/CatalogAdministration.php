<?php

declare(strict_types=1);

namespace App\Administration;

use App\Access\CatalogDirectory;
use App\Access\ArticleCodes;
use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\OpenApiContract;
use App\Security\AccessIdentity;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CatalogAdministration
{
    public function __construct(private readonly Connection $db, private readonly AccessIdentity $identity,
        private readonly CatalogDirectory $directory, private readonly OpenApiContract $contract,
        private readonly IdempotencyKey $keys, #[Autowire('%kernel.secret%')] private readonly string $secret) {}
    public function get(StockUser $actor, string $kind, string $id): array
    {
        $this->identity->requireRole($actor, Permission::CATALOG_ADMIN);
        $result = $this->directory->get($actor, $kind, $id);
        if ($kind === 'article' && $result['data']['deleted_at'] !== null) { throw new ApiProblem(409,'deleted_article','Cet article a été supprimé du catalogue. Son historique et ses stocks sont conservés.'); }
        return $result;
    }
    public function create(StockUser $actor, string $kind, \stdClass $body, string $key): array
    {
        $this->identity->requireRole($actor, Permission::CATALOG_ADMIN); $this->validate($kind, $body, true);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D', $key)) { throw new ApiProblem(422, 'invalid_idempotency_key', 'Clé de création invalide.'); }
        $path = $kind === 'article' ? '/api/v1/articles' : '/api/v1/categories';
        $fingerprint = hash_hmac('sha256', $this->keys->fingerprint($actor->id, 'POST', $path, $body), $this->secret);
        try {
            return $this->db->transactional(function () use ($actor, $kind, $body, $key, $fingerprint): array {
                $this->lock($actor);
                $previous = $this->db->fetchAssociative('SELECT request_fingerprint,response FROM catalog_creation_requests WHERE actor_id = ? AND resource_type = ? AND request_key = ?', [$actor->id,$kind,$key]);
                if ($previous) {
                    if (!hash_equals($previous['request_fingerprint'], $fingerprint)) { throw new ApiProblem(409, 'idempotency_conflict', 'Cette clé a déjà servi pour une autre création.'); }
                    return json_decode($previous['response'], true, flags: JSON_THROW_ON_ERROR);
                }
                if ($kind === 'article') {
                    $this->categoryTarget($body->category_id ?? null);
                    $codes = $body->scan_codes ?? [];
                    $this->checkCodes($codes);
                    $parameters = [trim($body->sku),trim($body->name),$body->description ?? '',$body->category_id ?? null,'unit',$body->active ?? true];
                    $types = [\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::BOOLEAN];
                    if ($codes !== []) { $parameters[] = json_encode($codes,JSON_THROW_ON_ERROR); $types[] = \Doctrine\DBAL\ParameterType::STRING; }
                    $id = $this->db->fetchOne('INSERT INTO articles (sku,name,description,category_id,unit_code,active'.($codes === [] ? '' : ',scan_codes').') VALUES (?,?,?,?,?,?'.($codes === [] ? '' : ',?::jsonb').') RETURNING id', $parameters, $types);
                } else {
                    $this->categoryTarget($body->parent_id ?? null);
                    $id = $this->db->fetchOne('INSERT INTO categories (name,parent_id,active) VALUES (?,?,?) RETURNING id', [trim($body->name),$body->parent_id ?? null,$body->active ?? true], [\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::BOOLEAN]);
                }
                $response = $this->directory->get($actor, $kind, $id);
                $this->audit($actor, $kind, $id, $kind.'.created', array_keys(get_object_vars($body)));
                $this->db->executeStatement('INSERT INTO catalog_creation_requests (actor_id,resource_type,request_key,request_fingerprint,article_id,category_id,response) VALUES (?,?,?,?,?,?,?::jsonb)', [$actor->id,$kind,$key,$fingerprint,$kind === 'article' ? $id : null,$kind === 'category' ? $id : null,json_encode($response,JSON_THROW_ON_ERROR)]);
                return $response;
            });
        } catch (UniqueConstraintViolationException) { throw new ApiProblem(409, 'sku_already_used', 'Cette référence est déjà utilisée, y compris par un article désactivé.'); }
    }
    public function update(StockUser $actor, string $kind, string $id, \stdClass $body): array
    {
        $this->identity->requireRole($actor, Permission::CATALOG_ADMIN); $this->validate($kind, $body, false);
        return $this->db->transactional(function () use ($actor,$kind,$id,$body): array {
            $this->lock($actor); $before = $this->directory->get($actor,$kind,$id)['data']; $id = $before['id'];
            if ($kind === 'article' && $before['deleted_at'] !== null) { throw new ApiProblem(409,'deleted_article','Cet article a été supprimé du catalogue. Son historique et ses stocks sont conservés.'); }
            if ($kind === 'article' && isset($body->scan_codes)) { $this->checkCodes($body->scan_codes, $id); }
            if ($kind === 'article' && $body->version !== $before['version']) { throw new ApiProblem(409, 'stale_article', 'Cet article a été modifié. Rechargez sa fiche avant de réessayer.'); }
            $next = array_replace($before,get_object_vars($body)); $next['name'] = trim($next['name']);
            foreach (['category_id','parent_id'] as $reference) { if (isset($next[$reference])) { $next[$reference] = strtolower($next[$reference]); } }
            if ($kind === 'article' && ($next['category_id'] !== $before['category_id'] || (!$before['active'] && $next['active']))) { $this->categoryTarget($next['category_id']); }
            if ($kind === 'category' && $next['parent_id'] !== $before['parent_id']) {
                $this->categoryTarget($next['parent_id']);
                if ($next['parent_id'] !== null && $this->db->fetchOne('WITH RECURSIVE parents AS (SELECT id,parent_id FROM categories WHERE id = ? UNION SELECT c.id,c.parent_id FROM categories c JOIN parents p ON c.id = p.parent_id) SELECT 1 FROM parents WHERE id = ?', [$next['parent_id'],$id])) { throw new ApiProblem(422,'category_cycle','Une catégorie ne peut pas être son propre ancêtre.'); }
            }
            if ($kind === 'category' && !$before['active'] && $next['active']) { $this->categoryTarget($next['parent_id']); }
            $fields = [];
            foreach (get_object_vars($body) as $field => $value) { if ($field !== 'version' && $before[$field] !== $next[$field]) { $fields[] = $field; } }
            if ($fields !== []) {
                if ($kind === 'article') {
                    if ($before['version'] >= 9007199254740991) { throw new ApiProblem(409,'article_version_limit','La version de cet article ne peut plus être incrémentée.'); }
                    $parameters = [$next['name'],$next['description'],$next['category_id'],$next['active']];
                    $types = [\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::BOOLEAN];
                    $codesSql = '';
                    if (in_array('scan_codes',$fields,true)) { $codesSql = ',scan_codes=?::jsonb'; $parameters[] = json_encode($next['scan_codes'],JSON_THROW_ON_ERROR); $types[] = \Doctrine\DBAL\ParameterType::STRING; }
                    $parameters[] = $id; $types[] = \Doctrine\DBAL\ParameterType::STRING;
                    $this->db->executeStatement('UPDATE articles SET name = ?,description = ?,category_id = ?,active = ?,version = version + 1,updated_at = clock_timestamp()'.$codesSql.' WHERE id = ?', $parameters, $types);
                } else {
                    $this->db->executeStatement('UPDATE categories SET name = ?,parent_id = ?,active = ? WHERE id = ?', [$next['name'],$next['parent_id'],$next['active'],$id], [\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::BOOLEAN,\Doctrine\DBAL\ParameterType::STRING]);
                }
                if ($kind === 'category' && $before['active'] !== $next['active']) { $this->setBranchActive($actor,$id,$next['active']); }
                $action = in_array('active',$fields,true) ? ($next['active'] ? '.reactivated' : '.archived') : '.updated';
                $this->audit($actor,$kind,$id,$kind.$action,$fields);
            }
            return $this->directory->get($actor,$kind,$id);
        });
    }
    /** Remove from the catalogue, preserving every stock balance and historical relation. */
    public function removeArticle(StockUser $actor, string $id, int $version): array
    {
        $this->identity->requireRole($actor, Permission::CATALOG_DELETE);
        if ($version < 1 || $version > 9007199254740991) { throw new ApiProblem(422,'invalid_version','Version de formulaire invalide. Rechargez la fiche.'); }
        return $this->db->transactional(function () use ($actor, $id, $version): array {
            // The same incompatible users/articles guards serialize removal with movements,
            // category changes, proposals and revocation of the actor's live permissions.
            $this->lock($actor, Permission::CATALOG_DELETE);
            $before = $this->directory->get($actor, 'article', $id)['data'];
            if ($before['deleted_at'] !== null) { return ['data' => $before]; }
            if ($before['version'] !== $version) { throw new ApiProblem(409,'stale_article','Cet article a été modifié. Rechargez sa fiche avant de le supprimer.'); }
            if ($version >= 9007199254740991) { throw new ApiProblem(409,'article_version_limit','La version de cet article ne peut plus être incrémentée.'); }
            $this->db->executeStatement('UPDATE articles SET active=false,deleted_at=clock_timestamp(),version=version+1,updated_at=clock_timestamp() WHERE id=?', [$before['id']]);
            $this->audit($actor, 'article', $before['id'], 'article.deleted', ['active','deleted_at']);
            return $this->directory->get($actor, 'article', $before['id']);
        });
    }

    /** Read-only business validation, shared by submission and administrative publication. */
    public function checkArticleProposal(StockUser $actor,string $operation,\stdClass $payload,?string $articleId=null,?int $version=null): void
    {
        $this->identity->requireRole($actor,Permission::CATALOG_PROPOSE);
        if (!in_array($operation,['create','update'],true)) { throw new ApiProblem(422,'invalid_operation','Type de proposition invalide.'); }
        $this->validatePayload($payload,$operation === 'create' ? 'ArticleCreate' : 'ArticleChanges');
        if ($operation === 'create') {
            $this->categoryTarget($payload->category_id ?? null);
            $this->checkCodes($payload->scan_codes ?? []);
            if ($this->db->fetchOne('SELECT 1 FROM articles WHERE lower(btrim(sku)) = lower(btrim(?))',[ $payload->sku ])) { throw new ApiProblem(409,'sku_already_used','Cette référence existe déjà dans le catalogue.'); }
            return;
        }
        if ($articleId === null || $version === null) { throw new ApiProblem(422,'invalid_proposal','L’article et sa version sont obligatoires.'); }
        $before=$this->directory->get($actor,'article',$articleId)['data'];
        if ($before['deleted_at'] !== null) { throw new ApiProblem(409,'deleted_article','Cet article a été supprimé du catalogue.'); }
        if (isset($payload->scan_codes)) { $this->checkCodes($payload->scan_codes, $articleId); }
        if ($version !== $before['version']) { throw new ApiProblem(409,'stale_article','L’article a changé. Rechargez sa fiche avant de proposer une modification.'); }
        $next=array_replace($before,get_object_vars($payload));
        $next['name']=trim($next['name']);if ($next['category_id'] !== null) { $next['category_id']=strtolower($next['category_id']); }
        if ($next['category_id'] !== $before['category_id'] || (!$before['active'] && $next['active'])) { $this->categoryTarget($next['category_id']); }
        foreach (get_object_vars($payload) as $field=>$value) { if ($next[$field] !== $before[$field]) { return; } }
        throw new ApiProblem(422,'no_article_change','Aucune modification de l’article n’a été proposée.');
    }
    private function lock(StockUser $actor, Permission $permission = Permission::CATALOG_ADMIN): void
    {
        $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
        $this->identity->requireRole($actor,$permission);
        $this->db->executeStatement('LOCK TABLE categories IN SHARE ROW EXCLUSIVE MODE');
        $this->db->executeStatement('LOCK TABLE articles IN SHARE ROW EXCLUSIVE MODE');
    }
    private function setBranchActive(StockUser $actor,string $root,bool $active): void
    {
        $branch = 'WITH RECURSIVE branch AS (SELECT id FROM categories WHERE id = ? UNION SELECT c.id FROM categories c JOIN branch b ON c.parent_id = b.id) ';
        $needsChange = $active ? 'NOT active' : 'active';
        $state = $active ? 'true' : 'false';
        if ($this->db->fetchOne($branch.'SELECT 1 FROM articles WHERE category_id IN (SELECT id FROM branch) AND deleted_at IS NULL AND '.$needsChange.' AND version >= 9007199254740991 LIMIT 1',[$root])) {
            throw new ApiProblem(409,'article_version_limit','Un article de cette branche ne peut plus changer de version. Aucun changement n’a été enregistré.');
        }
        $categories = $this->db->fetchFirstColumn($branch.'UPDATE categories SET active = '.$state.' WHERE id IN (SELECT id FROM branch) AND id <> ? AND '.$needsChange.' RETURNING id',[$root,$root]);
        $articles = $this->db->fetchFirstColumn($branch.'UPDATE articles SET active = '.$state.',version = version + 1,updated_at = clock_timestamp() WHERE category_id IN (SELECT id FROM branch) AND deleted_at IS NULL AND '.$needsChange.' RETURNING id',[$root]);
        $action = $active ? '.reactivated' : '.archived';
        foreach ($categories as $id) { $this->audit($actor,'category',$id,'category'.$action,['active'],$root); }
        foreach ($articles as $id) { $this->audit($actor,'article',$id,'article'.$action,['active'],$root); }
    }
    private function categoryTarget(?string $id): void
    {
        if ($id === null) { return; }
        $valid = $this->db->fetchOne('WITH RECURSIVE parents AS (SELECT id,parent_id,active FROM categories WHERE id = ? UNION SELECT c.id,c.parent_id,c.active FROM categories c JOIN parents p ON c.id = p.parent_id) SELECT count(*) > 0 AND COALESCE(bool_and(active),false) FROM parents',[$id]);
        if (!$valid) { throw new ApiProblem(422,'inactive_category','Choisissez une catégorie existante et active, dont les catégories parentes sont actives.'); }
    }
    private function validate(string $kind, \stdClass $body, bool $create): void
    {
        $schema = match ($kind) { 'article' => 'Article', 'category' => 'Category', default => throw new \InvalidArgumentException('Invalid catalogue kind.') };
        $this->validatePayload($body,$schema.($create?'Create':'Patch'));
    }
    private function validatePayload(\stdClass $body,string $schema): void
    {
        foreach (get_object_vars($body) as $value) { if (is_string($value) && (!preg_match('//u',$value) || str_contains($value,"\0"))) { throw new ApiProblem(422,'invalid_text','Les textes doivent être en UTF-8 et sans caractère nul.'); } }
        if (!$this->contract->valid($body,['$ref'=>'#/components/schemas/'.$schema]) || (isset($body->name) && trim($body->name)==='') || (isset($body->sku) && trim($body->sku)==='')) { throw new ApiProblem(422,'validation_failed','Vérifiez les champs obligatoires et les valeurs autorisées.'); }
        if (isset($body->scan_codes)) { $body->scan_codes = ArticleCodes::normalize($body->scan_codes); }
    }
    private function checkCodes(array $codes, ?string $articleId=null): void
    {
        foreach ($codes as $code) {
            $owner = $this->db->fetchOne('SELECT article_id FROM article_scan_codes WHERE code=?', [$code]);
            if ($owner && $owner !== $articleId) { throw new ApiProblem(409,'scan_code_already_used','Un de ces codes est déjà associé à un autre article, même désactivé.'); }
        }
    }
    private function audit(StockUser $actor,string $kind,string $id,string $action,array $fields,?string $cascadeFrom=null): void
    {
        $context=['fields'=>$fields];if ($cascadeFrom !== null) { $context['cascade_from']=$cascadeFrom; }
        $this->db->executeStatement('INSERT INTO audit_logs (actor_id,action,target_type,target_id,context) VALUES (?,?,?,?,?::jsonb)',[$actor->id,$action,$kind,$id,json_encode($context,JSON_THROW_ON_ERROR)]);
    }
}
