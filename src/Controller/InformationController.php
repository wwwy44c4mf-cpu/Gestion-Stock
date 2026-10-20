<?php

declare(strict_types=1);
namespace App\Controller;
use App\Api\{ApiProblem,IdempotencyKey,JsonBody};
use App\Information\InformationMessages;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
#[IsGranted('APP_INFORMATION_SEND')]
final class InformationController extends AbstractController
{
    public function __construct(private readonly InformationMessages $messages,private readonly Connection $db) {}
    private function actor(): StockUser { $actor=$this->getUser(); if(!$actor instanceof StockUser) {throw $this->createAccessDeniedException();} return $actor; }
    #[Route('/api/v1/notifications/information',name:'api_information_send',methods:['POST'],defaults:['_api_sensitive'=>true])]
    public function send(Request $request,JsonBody $json,IdempotencyKey $keys): JsonResponse
    {
        $result=$this->messages->queue($this->actor(),$json->read($request,'InformationCreate'),$keys->read($request));
        return $this->json(['data'=>$result['data']],202,['Location'=>$this->generateUrl('information_receipt',['id'=>$result['id']])]);
    }
    #[Route('/administration/informations',name:'information_list',methods:['GET'])]
    public function listing(Request $request): Response
    {
        $this->messages->authorize($this->actor());$params=$request->query->all();
        foreach($params as $key=>$value) {if(!in_array($key,['page','limit'],true)||!is_string($value)||!preg_match('/^[1-9][0-9]{0,4}$/D',$value)) {throw new ApiProblem(422,'invalid_query','Pagination invalide.');}}
        return $this->render('information/list.html.twig',['result'=>$this->messages->listing($this->actor(),(int)($params['page']??1),(int)($params['limit']??25))]);
    }
    #[Route('/administration/informations/nouveau',name:'information_new',methods:['GET','POST'],priority:20)]
    public function compose(Request $request): Response
    {
        $actor=$this->actor();$this->messages->authorize($actor);
        if($request->query->count()) {throw new ApiProblem(422,'invalid_query','Aucun paramètre attendu.');}
        $data=['subject'=>'','message'=>'','store_ids'=>[],'roles'=>[]];$key=bin2hex(random_bytes(16));$preview=null;$error=null;$status=200;
        if($request->isMethod('POST')) {
            try {
                $form=$request->request->all();
                if(strlen($request->getContent())>65536||$request->files->count()) {throw new ApiProblem(413,'payload_too_large','Formulaire trop volumineux.');}
                foreach($form as $name=>$value) {
                    if(!in_array($name,['subject','message','store_ids','roles','_request_key','_csrf_token','_preview_token','_preview_expires','_complete','action','confirm'],true)) {throw new ApiProblem(422,'invalid_form','Champ inattendu.');}
                    if(in_array($name,['store_ids','roles'],true)) {if(!is_array($value)||!array_is_list($value)||count($value)>($name==='roles'?3:1000)||count(array_filter($value,'is_string'))!==count($value)) {throw new ApiProblem(422,'invalid_form','Cible invalide.');}}
                    elseif(!is_string($value)) {throw new ApiProblem(422,'invalid_form','Formulaire invalide.');}
                }
                if(!$this->isCsrfTokenValid('information_compose',$form['_csrf_token']??null)) {throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page.');}
                if(($form['_complete']??'')!=='1') {throw new ApiProblem(422,'incomplete_form','Formulaire incomplet.');}
                $data=['subject'=>$form['subject']??'','message'=>$form['message']??'','store_ids'=>$form['store_ids']??[],'roles'=>$form['roles']??[]];$key=$form['_request_key']??'';
                $action=$form['action']??'';
                if($action==='confirm') {
                    if(($form['confirm']??'')!=='1'||!preg_match('/^[0-9]{1,12}$/D',$form['_preview_expires']??'')) {throw new ApiProblem(422,'confirmation_required','Confirmez les destinataires et le message.');}
                    $result=$this->messages->queue($actor,(object)$data,$key,true,(int)$form['_preview_expires'],$form['_preview_token']??'');
                    return $this->redirectToRoute('information_receipt',['id'=>$result['id']],303);
                }
                if(!in_array($action,['preview','edit'],true)) {throw new ApiProblem(422,'invalid_form','Action invalide.');}
                if($action==='preview') {$preview=$this->messages->preview($actor,(object)$data,$key);$data=(array)$preview['body'];}
            } catch(ApiProblem $e) {$error=$e->getMessage();$status=$e->status;}
        }
        return $this->render('information/compose.html.twig',['data'=>$data,'request_key'=>$key,'preview'=>$preview,'error'=>$error,'stores'=>$this->db->fetchAllAssociative('SELECT id,name FROM stores WHERE active ORDER BY name,id')],new Response(status:$status));
    }
    #[Route('/administration/informations/{id}',name:'information_receipt',methods:['GET'])]
    public function receipt(string $id,Request $request): Response
    {
        if($request->query->count()) {throw new ApiProblem(422,'invalid_query','Aucun paramètre attendu.');}
        return $this->render('information/receipt.html.twig',['campaign'=>$this->messages->receipt($this->actor(),$id)]);
    }
}
