<?php

declare(strict_types=1);
namespace App\Controller;
use App\Access\CorrectionDirectory;
use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\JsonBody;
use App\Security\AccessIdentity;
use App\Security\Permission;
use App\Security\StockUser;
use App\Stock\StockCorrectionEngine;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class StockCorrectionController extends AbstractController
{
    public function __construct(private readonly StockCorrectionEngine $engine,private readonly CorrectionDirectory $directory,private readonly AccessIdentity $identity) {}
    #[Route('/api/v1/movements/{id}/correct',name:'api_stock_correct',methods:['POST'])]
    #[Route('/api/v1/movements/{id}/reverse',name:'api_stock_reverse',methods:['POST'])]
    public function api(string $id,Request $request,JsonBody $json,IdempotencyKey $keys): JsonResponse
    {
        $actor=$this->actor();$cancel=$request->attributes->get('_route')==='api_stock_reverse';
        return $this->json($this->engine->correct($actor,$id,$json->read($request,$cancel?'ReversalCreate':'MovementCorrectionCreate'),$keys->read($request),$cancel),201);
    }
    #[Route('/stocks/mouvements/{id}/corriger',name:'stocks_correct',methods:['GET','POST'])]
    #[Route('/stocks/mouvements/{id}/annuler',name:'stocks_reverse',methods:['GET','POST'])]
    public function form(string $id,Request $request,CsrfTokenManagerInterface $csrf): Response
    {
        $actor=$this->actor();$original=$this->directory->get($actor,$id);$cancel=$request->attributes->get('_route')==='stocks_reverse';
        $data=['quantity'=>(string)$original['quantity'],'reason'=>''];$key=bin2hex(random_bytes(16));$error=null;$status=200;
        if ($request->isMethod('GET') && $request->query->count()>0) { throw new ApiProblem(422,'invalid_query','Ce formulaire n’accepte pas de paramètres supplémentaires.'); }
        if ($request->isMethod('POST')) {
            try {
                if(strlen($request->getContent())>65536) { throw new ApiProblem(413,'payload_too_large','Le formulaire est trop volumineux.'); }
                $raw=$request->request->all();$token=$raw['_csrf_token']??'';
                if(!is_string($token) || !$csrf->isTokenValid(new CsrfToken('stock_correction_'.strtolower($id),$token))) { throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page.'); }
                if($request->query->count()>0 || $request->files->count()>0) { throw new ApiProblem(422,'invalid_form','Aucun paramètre supplémentaire ni fichier n’est accepté.'); }
                foreach($raw as $name=>$value) { if(!in_array($name,$cancel?['_csrf_token','_movement_key','reason']:['_csrf_token','_movement_key','reason','quantity'],true) || !is_string($value)) { throw new ApiProblem(422,'invalid_form','Un champ du formulaire est invalide.'); } }
                $key=$raw['_movement_key']??'';$data['reason']=$raw['reason']??'';
                $body=(object)['reason'=>$data['reason']];
                if(!$cancel) { $data['quantity']=$raw['quantity']??'';if(!preg_match('/^[1-9][0-9]{0,15}$/D',$data['quantity'])) { throw new ApiProblem(422,'invalid_quantity','Saisissez une quantité correcte entière strictement positive. Pour supprimer l’effet du mouvement, choisissez son annulation.'); }$body->quantity=(int)$data['quantity']; }
                $this->engine->correct($actor,$id,$body,$key,$cancel);
                $this->addFlash('success',$cancel?'Le mouvement a été annulé. Son historique est conservé.':'La quantité a été corrigée. Le mouvement d’origine est conservé dans l’historique.');
                return $this->redirectToRoute('stocks_detail',['storeId'=>$original['source_store_id']??$original['destination_store_id'],'articleId'=>$original['article_id']],303);
            } catch(ApiProblem $problem) { $error=$problem->getMessage();$status=$problem->status; }
        }
        return $this->render('stocks/correction.html.twig',['original'=>$original,'cancel'=>$cancel,'item'=>$data,'movement_key'=>$key,'error'=>$error],new Response(status:$status));
    }
    private function actor(): StockUser
    {
        $actor=$this->getUser();if(!$actor instanceof StockUser) { throw new ApiProblem(401,'authentication_required','Connectez-vous.'); }
        $this->identity->requireRole($actor,Permission::MOVEMENT_REVERSE);return $actor;
    }
}
