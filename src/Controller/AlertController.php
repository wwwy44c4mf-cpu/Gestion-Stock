<?php

declare(strict_types=1);
namespace App\Controller;
use App\Access\AccessDirectory;
use App\Access\StockDirectory;
use App\Alert\AlertDirectory;
use App\Alert\AlertRecipients;
use App\Api\ApiProblem;
use App\Api\JsonBody;
use App\Api\QueryParameters;
use App\Security\Permission;
use App\Security\StockUser;
use App\Ui\StoreContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
final class AlertController extends AbstractController
{
    public function __construct(private readonly AlertDirectory $alerts,private readonly AlertRecipients $recipients,private readonly AccessDirectory $directory,private readonly StockDirectory $stocks) {}
    #[Route('/api/v1/alerts',name:'api_alerts_list',methods:['GET'])]
    public function listing(Request $request,QueryParameters $query): JsonResponse {return $this->json($this->alerts->listing($this->actor(),$query->read($request,'listAlerts')));}
    #[Route('/api/v1/stores/{id}/alert-recipients',name:'api_alert_recipients',methods:['GET'])]
    public function recipients(string $id,Request $request,QueryParameters $query): JsonResponse
    {
        $filters=$query->read($request,'listAlertRecipients');$state=$this->recipients->state($this->actor(),$id);
        return $this->json($this->recipients->paginate($state['recipients'],$filters),headers:['X-Recipients-Mode'=>$state['use_defaults']?'default':'custom','ETag'=>'"'.$state['revision'].'"']);
    }
    #[Route('/api/v1/stores/{id}/alert-recipient-options',name:'api_alert_recipient_options',methods:['GET'])]
    public function options(string $id,Request $request,QueryParameters $query): JsonResponse {return $this->json($this->recipients->page($this->actor(),$id,$query->read($request,'listAlertRecipientOptions'),true));}
    #[Route('/api/v1/stores/{id}/alert-recipients',name:'api_alert_recipients_replace',methods:['PUT'])]
    public function replace(string $id,Request $request,JsonBody $body): JsonResponse
    {
        $revision=$request->headers->get('If-Match');if($revision!==null) {$revision=trim($revision,'"');}
        return $this->json($this->recipients->replace($this->actor(),$id,$body->read($request,'RecipientsWrite'),$revision));
    }
    #[Route('/alertes',name:'alerts_list',methods:['GET'])]
    public function index(Request $request,QueryParameters $query,StoreContext $context): Response
    {
        $actor=$this->actor();$copy=$request->duplicate();foreach(['store_id','article_id','level','status'] as $key) {if(($copy->query->all()[$key]??null)==='') {$copy->query->remove($key);}}
        if(!$request->query->has('status')) {$copy->query->set('status','active');}if(!$copy->query->has('order')) {$copy->query->set('order','desc');}$filters=$query->read($copy,'listAlerts');
        if(!$request->query->has('store_id')&&($store=$context->read($request,$actor)['selected'])!==null) {$filters['store_id']=$store;}
        $result=$this->alerts->listing($actor,$filters);return $this->render('alerts/list.html.twig',['result'=>$result,'filters'=>$filters,'labels'=>$this->stocks->labels($result['data']),'stores'=>$this->directory->storeOptions($actor,Permission::ALERT_READ),'editable_stores'=>$this->directory->storeOptions($actor,Permission::ALERT_RECIPIENTS)]);
    }
    #[Route('/alertes/{id}/destinataires',name:'alerts_recipients',methods:['GET','POST'])]
    public function form(string $id,Request $request,CsrfTokenManagerInterface $csrf): Response
    {
        $actor=$this->actor();$this->recipients->assertWrite($actor,$id);$id=strtolower($id);$state=$this->recipients->state($actor,$id);$revision=$state['revision'];$selected=$state['recipients'];$error=null;$status=200;
        if($request->query->count()>0) {throw new ApiProblem(422,'invalid_query','Aucun paramètre de recherche n’est accepté.');}if(count($state['options'])>1000) {throw new ApiProblem(409,'recipient_limit','La liste est trop grande pour ce formulaire. Utilisez la configuration API paginée.');}
        if($request->isMethod('POST')) {
            try {
                if(strlen($request->getContent())>65536||$request->files->count()>0) {throw new ApiProblem(422,'invalid_form','Formulaire invalide ou trop volumineux.');}$data=$request->request->all();
                foreach($data as $key=>$value) {if(!in_array($key,['_csrf_token','_revision','_complete','action','recipients'],true)) {throw new ApiProblem(422,'invalid_form','Champ inattendu.');}if($key!=='recipients'&&!is_string($value)) {throw new ApiProblem(422,'invalid_form','Champ invalide.');}}
                $revision=$data['_revision']??'';$raw=$data['recipients']??[];$selected=[];if(!is_array($raw)||count($raw)>1000) {throw new ApiProblem(422,'invalid_form','Liste de destinataires invalide.');}
                foreach($raw as $value) {if(!is_string($value)||!preg_match('/^([a-f0-9-]{36}):(low|critical|daily_summary)$/Di',$value,$matches)) {throw new ApiProblem(422,'invalid_form','Destinataire invalide.');}$selected[]=['user_id'=>$matches[1],'alert_type'=>$matches[2]];}
                if(($data['_complete']??'')!=='1') {throw new ApiProblem(422,'incomplete_form','Le formulaire est incomplet. Rechargez la page.');}
                if(!$csrf->isTokenValid(new CsrfToken('alert_recipients_'.$id,$data['_csrf_token']??''))) {throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page.');}
                $action=$data['action']??'';if(!in_array($action,['save','defaults'],true)) {throw new ApiProblem(422,'invalid_form','Action invalide.');}
                $this->recipients->replace($actor,$id,(object)['recipients'=>$action==='defaults'?[]:array_map(static fn($r)=>(object)$r,$selected),'use_defaults'=>$action==='defaults'],$revision);
                $this->addFlash('success',$action==='defaults'?'La liste par défaut est rétablie.':'Les destinataires ont été enregistrés.');return $this->redirectToRoute('alerts_recipients',['id'=>$id],303);
            }catch(ApiProblem $problem) {$error=$problem->getMessage();$status=$problem->status;}
        }
        $keys=array_map(static fn($r)=>strtolower($r['user_id']).':'.$r['alert_type'],$selected);$store=null;foreach($this->directory->storeOptions($actor,Permission::ALERT_RECIPIENTS) as $row) {if($row['id']===$id) {$store=$row;break;}}
        return $this->render('alerts/recipients.html.twig',['store'=>$store,'state'=>$state,'selected'=>$keys,'revision'=>$revision,'error'=>$error],new Response(status:$status));
    }
    private function actor(): StockUser {$actor=$this->getUser();if(!$actor instanceof StockUser) {throw new ApiProblem(401,'authentication_required','Connectez-vous.');}return $actor;}
}
