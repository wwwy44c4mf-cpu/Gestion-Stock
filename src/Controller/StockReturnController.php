<?php

declare(strict_types=1);
namespace App\Controller;
use App\Access\ConsumptionDirectory;
use App\Access\ReturnDirectory;
use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\JsonBody;
use App\Security\AccessIdentity;
use App\Security\AccessPolicy;
use App\Security\AccessTarget;
use App\Security\Permission;
use App\Security\StockUser;
use App\Stock\StockReturnEngine;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class StockReturnController extends AbstractController
{
    public function __construct(private readonly StockReturnEngine $engine,private readonly ConsumptionDirectory $consumptions,private readonly ReturnDirectory $returns,
        private readonly AccessIdentity $identity,private readonly AccessPolicy $policy) {}
    #[Route('/api/v1/movements/returns',name:'api_stock_returns_create',methods:['POST'])]
    public function create(Request $request,JsonBody $json,IdempotencyKey $key): JsonResponse
    {
        $actor=$this->actor();$result=$this->engine->record($actor,$json->read($request,'ReturnCreate'),$key->read($request));
        return $this->json($result,201,['Location'=>$this->generateUrl('consumptions_return_receipt',['id'=>$result['data']['id']])]);
    }
    #[Route('/consommations/retours',name:'consumptions_return_list',priority:20,methods:['GET'])]
    public function listing(Request $request): Response
    {
        $actor=$this->actor();$page=1;
        foreach($request->query->all() as $name=>$value) {
            if($name!=='page' || !is_string($value) || !preg_match('/^[1-9][0-9]{0,4}$/D',$value)) { throw new ApiProblem(422,'invalid_query','Pagination invalide.'); }$page=(int)$value;
        }
        return $this->render('returns/list.html.twig',['result'=>$this->returns->eligible($actor,$page)]);
    }
    #[Route('/consommations/{id}/retour',name:'consumptions_return_new',methods:['GET','POST'])]
    public function form(string $id,Request $request,CsrfTokenManagerInterface $csrf): Response
    {
        $actor=$this->actor();$origin=$this->consumptions->get($actor,$id);$this->policy->assert($actor,Permission::RETURN_CREATE,AccessTarget::consumption($id));
        $data=['quantity'=>'','comment'=>''];$key=bin2hex(random_bytes(16));$error=null;$status=200;
        if($request->isMethod('GET') && $request->query->count()>0) { throw new ApiProblem(422,'invalid_query','Ce formulaire n’accepte pas de paramètres supplémentaires.'); }
        if($request->isMethod('POST')) {
            try {
                if(strlen($request->getContent())>65536) { throw new ApiProblem(413,'payload_too_large','Le formulaire est trop volumineux.'); }
                $raw=$request->request->all();$token=$raw['_csrf_token']??'';
                if(!is_string($token) || !$csrf->isTokenValid(new CsrfToken('stock_return_'.strtolower($id),$token))) { throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page.'); }
                if($request->query->count()>0 || $request->files->count()>0) { throw new ApiProblem(422,'invalid_form','Aucun paramètre supplémentaire ni fichier n’est accepté.'); }
                foreach($raw as $name=>$value) { if(!in_array($name,['_csrf_token','_movement_key','quantity','comment'],true) || !is_string($value)) { throw new ApiProblem(422,'invalid_form','Un champ du formulaire est invalide.'); } }
                $key=$raw['_movement_key']??'';foreach($data as $name=>$_) { $data[$name]=$raw[$name]??''; }
                if(!preg_match('/^[1-9][0-9]{0,15}$/D',$data['quantity'])) { throw new ApiProblem(422,'invalid_quantity','Saisissez une quantité entière strictement positive.'); }
                $body=(object)['consumption_id'=>$id,'quantity'=>(int)$data['quantity']];if($data['comment']!=='') { $body->comment=$data['comment']; }
                $result=$this->engine->record($actor,$body,$key)['data'];$this->addFlash('success','Le retour de '.$result['quantity'].' unité'.($result['quantity']>1?'s':'').' a été enregistré dans le magasin d’origine.');
                return $this->redirectToRoute('consumptions_return_receipt',['id'=>$result['id']],303);
            } catch(ApiProblem $problem) { $error=$problem->getMessage();$status=$problem->status; }
        }
        return $this->render('returns/form.html.twig',['origin'=>$origin,'item'=>$data,'movement_key'=>$key,'error'=>$error],new Response(status:$status));
    }
    #[Route('/consommations/retours/{id}',name:'consumptions_return_receipt',priority:10,methods:['GET'])]
    public function receipt(string $id): Response
    {
        $actor=$this->actor();$row=$this->returns->receipt($actor,$id);$canCorrect=false;
        if(in_array($actor->role,['ADMIN','MANAGER'],true)) { try { $this->policy->assert($actor,Permission::MOVEMENT_REVERSE,AccessTarget::movement($id));$canCorrect=true; }catch(ApiProblem) {} }
        return $this->render('returns/receipt.html.twig',['return'=>$row,'can_correct'=>$canCorrect,'stock_target'=>AccessTarget::store($row['store_id'])]);
    }
    private function actor(): StockUser
    {
        $actor=$this->getUser();if(!$actor instanceof StockUser) { throw new ApiProblem(401,'authentication_required','Connectez-vous.'); }
        $this->identity->requireRole($actor,Permission::RETURN_CREATE);return $actor;
    }
}
