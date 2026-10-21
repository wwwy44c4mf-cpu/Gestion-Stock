<?php

declare(strict_types=1);
namespace App\Controller;

use App\Access\AccessDirectory;
use App\Api\{ApiProblem,QueryParameters};
use App\Audit\AuditDirectory;
use App\Security\{Permission,StockUser};
use App\Ui\StoreContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse,Request,Response};
use Symfony\Component\Routing\Attribute\Route;

final class AuditController extends AbstractController
{
    public function __construct(private readonly AuditDirectory $audit) {}
    #[Route('/api/v1/audit-logs',name:'api_audit_list',methods:['GET'])]
    public function api(Request $request,QueryParameters $query): JsonResponse {return $this->json($this->audit->listing($this->actor(),$query->read($request,'listAuditLogs')));}
    #[Route('/audit',name:'audit_list',methods:['GET'])]
    public function index(Request $request,QueryParameters $query,AccessDirectory $directory,StoreContext $context): Response
    {
        $actor=$this->actor();$copy=$request->duplicate();foreach($request->query->all() as $key=>$value) {if(in_array($key,['date_from','date_to','store_id','actor_id','action'],true)&&$value==='') {$copy->query->remove($key);}}
        if(!$copy->query->has('order')) {$copy->query->set('order','desc');}$filters=$query->read($copy,'listAuditLogs');foreach(['store_id','actor_id'] as $key) {if(isset($filters[$key])) {$filters[$key]=strtolower($filters[$key]);}}
        if(!$request->query->has('store_id')&&($selected=$context->read($request,$actor)['selected'])!==null) {$filters['store_id']=$selected;}
        $result=$this->audit->listing($actor,$filters);foreach($result['data'] as &$item) {$item['context']=json_decode(json_encode($item['context'],JSON_THROW_ON_ERROR),true,flags:JSON_THROW_ON_ERROR);}unset($item);
        return $this->render('audit/list.html.twig',['result'=>$result,'filters'=>$filters,'options'=>$this->audit->options($actor),'stores'=>$directory->storeOptions($actor,Permission::AUDIT_READ)]);
    }
    private function actor(): StockUser {$actor=$this->getUser();if(!$actor instanceof StockUser) {throw new ApiProblem(401,'authentication_required','Connectez-vous.');}return $actor;}
}
