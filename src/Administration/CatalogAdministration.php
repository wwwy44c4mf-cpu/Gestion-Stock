<?php

declare(strict_types=1);

namespace App\Administration;

use App\Access\CatalogDirectory;
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
        return $this->directory->get($actor, $kind, $id);
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
                    $id = $this->db->fetchOne('INSERT INTO articles (sku,name,description,category_id,unit_code,active) VALUES (?,?,?,?,?,?) RETURNING id', [trim($body->sku),trim($body->name),$body->description ?? '',$body->category_id ?? null,'unit',$body->active ?? true], [\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::BOOLEAN]);
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
                    $this->db->executeStatement('UPDATE articles SET name = ?,description = ?,category_id = ?,active = ?,version = version + 1,updated_at = clock_timestamp() WHERE id = ?', [$next['name'],$next['description'],$next['category_id'],$next['active'],$id], [\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::BOOLEAN,\Doctrine\DBAL\ParameterType::STRING]);
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
    private function lock(StockUser $actor): void
    {
        $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
        $this->identity->requireRole($actor,Permission::CATALOG_ADMIN);
        $this->db->executeStatement('LOCK TABLE categories IN SHARE ROW EXCLUSIVE MODE');
        $this->db->executeStatement('LOCK TABLE articles IN SHARE ROW EXCLUSIVE MODE');
    }
    private function setBranchActive(StockUser $actor,string $root,bool $active): void
    {
        $branch = 'WITH RECURSIVE branch AS (SELECT id FROM categories WHERE id = ? UNION SELECT c.id FROM categories c JOIN branch b ON c.parent_id = b.id) ';
        $needsChange = $active ? 'NOT active' : 'active';
        $state = $active ? 'true' : 'false';
        if ($this->db->fetchOne($branch.'SELECT 1 FROM articles WHERE category_id IN (SELECT id FROM branch) AND '.$needsChange.' AND version >= 9007199254740991 LIMIT 1',[$root])) {
            throw new ApiProblem(409,'article_version_limit','Un article de cette branche ne peut plus changer de version. Aucun changement n’a été enregistré.');
        }
        $categories = $this->db->fetchFirstColumn($branch.'UPDATE categories SET active = '.$state.' WHERE id IN (SELECT id FROM branch) AND id <> ? AND '.$needsChange.' RETURNING id',[$root,$root]);
        $articles = $this->db->fetchFirstColumn($branch.'UPDATE articles SET active = '.$state.',version = version + 1,updated_at = clock_timestamp() WHERE category_id IN (SELECT id FROM branch) AND '.$needsChange.' RETURNING id',[$root]);
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
        foreach (get_object_vars($body) as $value) { if (is_string($value) && (!preg_match('//u',$value) || str_contains($value,"\0"))) { throw new ApiProblem(422,'invalid_text','Les textes doivent être en UTF-8 et sans caractère nul.'); } }
        if (!$this->contract->valid($body,['$ref'=>'#/components/schemas/'.$schema.($create?'Create':'Patch')]) || (isset($body->name) && trim($body->name)==='') || (isset($body->sku) && trim($body->sku)==='')) { throw new ApiProblem(422,'validation_failed','Vérifiez les champs obligatoires et les valeurs autorisées.'); }
    }
    private function audit(StockUser $actor,string $kind,string $id,string $action,array $fields,?string $cascadeFrom=null): void
    {
        $context=['fields'=>$fields];if ($cascadeFrom !== null) { $context['cascade_from']=$cascadeFrom; }
        $this->db->executeStatement('INSERT INTO audit_logs (actor_id,action,target_type,target_id,context) VALUES (?,?,?,?,?::jsonb)',[$actor->id,$action,$kind,$id,json_encode($context,JSON_THROW_ON_ERROR)]);
    }
}
