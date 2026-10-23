<?php

declare(strict_types=1);
namespace App\Controller;
use App\Access\AccessDirectory;
use App\Api\{ApiProblem,IdempotencyKey,JsonBody};
use App\Security\{AccessIdentity,AccessPolicy,AccessTarget,Permission,StockUser};
use App\Stock\ReturnBatchEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\{CsrfToken,CsrfTokenManagerInterface};

final class ReturnBatchController extends AbstractController
{
    public function __construct(private readonly ReturnBatchEngine $engine, private readonly AccessIdentity $identity,
        private readonly AccessPolicy $policy, private readonly AccessDirectory $directory, private readonly Connection $db) {}

    #[Route('/api/v1/movements/return-batches',name:'api_return_batch_create',methods:['POST'])]
    public function create(Request $request,JsonBody $json,IdempotencyKey $key): JsonResponse
    {
        $result=$this->engine->record($this->actor(),$json->read($request,'ReturnBatchCreate'),$key->read($request));
        return $this->json($result,201,['Location'=>$this->generateUrl('consumptions_return_batch_receipt',['id'=>$result['data']['id']])]);
    }

    #[Route('/consommations/retours/nouveau',name:'consumptions_return_batch_new',priority:40,methods:['GET','POST'],defaults:['_camera_scan'=>true])]
    public function form(Request $request,CsrfTokenManagerInterface $csrf): Response
    {
        $actor=$this->actor();$stores=$this->directory->storeOptions($actor,Permission::RETURN_CREATE);
        $articles=$this->db->fetchAllAssociative('SELECT id,sku,name,active FROM articles ORDER BY name,id');
        $canChoose=in_array($actor->role,['ADMIN','MANAGER'],true);
        $beneficiaries=$canChoose && $stores!==[]?$this->directory->beneficiaryOptions($actor):[];
        $favorite=$actor->favoriteStoreId;
        $data=['article_id'=>'','store_id'=>in_array($favorite,array_column($stores,'id'),true)?$favorite:'','consumer_id'=>$actor->id,'quantity'=>'','comment'=>''];
        $key=bin2hex(random_bytes(16));$error=null;$status=200;
        if($request->isMethod('GET')) {
            foreach($request->query->all() as $name=>$value) {
                if($name!=='article_id' || !is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$value)) { throw new ApiProblem(422,'invalid_query','Présélection invalide.'); }
                $data[$name]=strtolower($value);
                if(!in_array($data[$name],array_column($articles,'id'),true)) { throw new ApiProblem(404,'article_not_found','Article introuvable.'); }
            }
        } else {
            try {
                if(strlen($request->getContent())>65536) { throw new ApiProblem(413,'payload_too_large','Le formulaire est trop volumineux.'); }
                $raw=$request->request->all();$token=$raw['_csrf_token']??'';
                if(!is_string($token) || !$csrf->isTokenValid(new CsrfToken('stock_return_batch',$token))) { throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page.'); }
                if($request->query->count()>0 || $request->files->count()>0) { throw new ApiProblem(422,'invalid_form','Paramètres supplémentaires refusés.'); }
                foreach($raw as $name=>$value) { if(!in_array($name,['_csrf_token','_movement_key',...array_keys($data)],true) || !is_string($value)) { throw new ApiProblem(422,'invalid_form','Champ invalide.'); } }
                foreach($data as $name=>$_) { $data[$name]=$raw[$name]??($name==='consumer_id'?$actor->id:''); }
                $key=$raw['_movement_key']??'';
                if(!preg_match('/^[1-9][0-9]{0,15}$/D',$data['quantity'])) { throw new ApiProblem(422,'invalid_quantity','Saisissez une quantité entière positive.'); }
                $body=(object)$data;$body->quantity=(int)$data['quantity'];
                $result=$this->engine->record($actor,$body,$key)['data'];
                $this->addFlash('success','Le dépôt de '.$result['quantity'].' unité(s) a été enregistré.');
                return $this->redirectToRoute('consumptions_return_batch_receipt',['id'=>$result['id']],303);
            } catch(ApiProblem $problem) { $error=$problem->getMessage();$status=$problem->status; }
        }
        return $this->render('returns/batch_form.html.twig',['item'=>$data,'stores'=>$stores,'articles'=>$articles,'can_choose_beneficiary'=>$canChoose,'beneficiaries'=>$beneficiaries,'movement_key'=>$key,'error'=>$error],new Response(status:$status));
    }

    #[Route('/consommations/retours/depot/{id}',name:'consumptions_return_batch_receipt',priority:40,methods:['GET'])]
    public function receipt(string $id): Response
    {
        $actor=$this->actor();
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$id)) { throw new ApiProblem(404,'return_not_found','Retour introuvable.'); }
        $row=$this->db->fetchAssociative("SELECT g.*,a.sku,a.name article_name,s.code store_code,s.name store_name,s.active store_active,
         (SELECT c.consumer_id FROM stock_movements r JOIN stock_movements c ON c.id=r.related_movement_id WHERE r.return_group_id=g.id ORDER BY r.created_at,r.id LIMIT 1) consumer_id
         FROM stock_return_groups g JOIN articles a ON a.id=g.article_id JOIN stores s ON s.id=g.store_id WHERE g.id=?",[$id]);
        if(!$row) { throw new ApiProblem(404,'return_not_found','Retour introuvable.'); }
        if($actor->role==='USER') {
            if($row['consumer_id']!==$actor->id || !$row['store_active']) { throw new ApiProblem(404,'return_not_found','Retour introuvable.'); }
        } else { $this->policy->assert($actor,Permission::STOCK_READ,AccessTarget::store($row['store_id'])); }
        // Display effective quantities after any chain of corrections/reversals.
        $row['effective_quantity']=(int)$this->db->fetchOne("WITH RECURSIVE items(id) AS (SELECT id FROM stock_movements WHERE return_group_id=? UNION SELECT c.replacement_id FROM movement_corrections c JOIN items i ON c.original_id=i.id) SELECT coalesce(sum(m.quantity),0) FROM items JOIN stock_movements m USING(id) WHERE NOT EXISTS(SELECT 1 FROM stock_movements u WHERE u.type='reversal' AND u.related_movement_id=m.id)",[$id]);
        $movements=$this->db->fetchAllAssociative('SELECT id,quantity FROM stock_movements WHERE return_group_id=? ORDER BY created_at,id',[$id]);
        $row['author_name']=$this->db->fetchOne("SELECT CASE WHEN u.anonymized_at IS NULL THEN u.name ELSE 'Compte anonymisé' END FROM stock_movements r JOIN users u ON u.id=r.author_id WHERE r.return_group_id=? ORDER BY r.created_at,r.id LIMIT 1",[$id]);
        $row['consumer_name']=$this->db->fetchOne("SELECT CASE WHEN anonymized_at IS NULL THEN name ELSE 'Compte anonymisé' END FROM users WHERE id=?",[$row['consumer_id']]);
        $canReadOrigins=$actor->role==='ADMIN';
        return $this->render('returns/batch_receipt.html.twig',['deposit'=>$row,'movements'=>$movements,'can_read_origins'=>$canReadOrigins]);
    }
    private function actor(): StockUser
    {
        $actor=$this->getUser();if(!$actor instanceof StockUser) { throw new ApiProblem(401,'authentication_required','Connectez-vous.'); }
        $this->identity->requireRole($actor,Permission::RETURN_CREATE);return $actor;
    }
}
