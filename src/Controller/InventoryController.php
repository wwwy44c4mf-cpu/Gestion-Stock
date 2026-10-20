<?php

declare(strict_types=1);
namespace App\Controller;
use App\Access\AccessDirectory;
use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\JsonBody;
use App\Api\QueryParameters;
use App\Inventory\InventoryDirectory;
use App\Inventory\InventoryWorkflow;
use App\Security\AccessIdentity;
use App\Security\AccessTarget;
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

final class InventoryController extends AbstractController
{
    public function __construct(private readonly InventoryDirectory $inventories,private readonly InventoryWorkflow $workflow,private readonly AccessDirectory $directory,private readonly AccessIdentity $identity) {}
    #[Route('/api/v1/inventories',name:'api_inventories_list',methods:['GET'])]
    public function listing(Request $request,QueryParameters $query): JsonResponse
    {
        return $this->json($this->inventories->listing($this->actor(),$query->read($request,'listInventories')));
    }
    #[Route('/api/v1/inventories',name:'api_inventories_create',methods:['POST'])]
    public function create(Request $request,JsonBody $json,IdempotencyKey $key): JsonResponse
    {
        $result=$this->workflow->create($this->actor(),$json->read($request,'InventoryCreate'),$key->read($request));
        return $this->json($result,201,['Location'=>$this->generateUrl('api_inventories_detail',['id'=>$result['data']['id']])]);
    }
    #[Route('/api/v1/inventories/{id}',name:'api_inventories_detail',methods:['GET'])]
    public function resource(string $id,Request $request,QueryParameters $query): JsonResponse
    {
        $query->read($request,'getInventory');return $this->json($this->inventories->get($this->actor(),$id));
    }
    #[Route('/api/v1/inventories/{id}/lines',name:'api_inventories_lines',methods:['GET'])]
    public function lines(string $id,Request $request,QueryParameters $query): JsonResponse
    {
        return $this->json($this->inventories->lines($this->actor(),$id,$query->read($request,'listInventoryLines')));
    }
    #[Route('/api/v1/inventories/{id}/{action}',name:'api_inventories_transition',requirements:['action'=>'start|submit|cancel'],methods:['POST'])]
    public function change(string $id,string $action,Request $request,IdempotencyKey $key): JsonResponse
    {
        if($request->query->count()>0 || trim($request->getContent())!=='') {throw new ApiProblem(422,'invalid_body','Cette action n’accepte ni corps ni paramètre de recherche.');}
        return $this->json($this->workflow->transition($this->actor(),$id,$action,$key->read($request)));
    }
    #[Route('/inventaires',name:'inventories_list',methods:['GET'])]
    public function index(Request $request,QueryParameters $query,StoreContext $context): Response
    {
        $actor=$this->actor();$copy=$request->duplicate();foreach(['store_id','status'] as $key) {if($copy->query->get($key)==='') {$copy->query->remove($key);}}
        if(!$copy->query->has('order')) {$copy->query->set('order','desc');}$filters=$query->read($copy,'listInventories');
        if(!$request->query->has('store_id') && ($store=$context->read($request,$actor)['selected'])!==null) {$filters['store_id']=$store;}
        $result=$this->inventories->listing($actor,$filters);
        return $this->render('inventories/list.html.twig',['result'=>$result,'filters'=>$filters,'labels'=>$this->inventories->labels($result['data']),'stores'=>$this->directory->storeOptions($actor,Permission::INVENTORY_READ),'can_create'=>$this->directory->storeOptions($actor,Permission::INVENTORY_WRITE)!==[]]);
    }
    #[Route('/inventaires/nouveau',name:'inventories_new',priority:20,methods:['GET','POST'])]
    public function form(Request $request,CsrfTokenManagerInterface $csrf,StoreContext $context): Response
    {
        $actor=$this->actor();$stores=$this->directory->storeOptions($actor,Permission::INVENTORY_WRITE);$store='';$key=bin2hex(random_bytes(16));$error=null;$status=200;
        if($request->isMethod('GET')) {
            if($request->query->count()>0) {throw new ApiProblem(422,'invalid_query','Aucun paramètre de recherche n’est accepté.');}$store=$context->read($request,$actor)['selected']??'';
        } else {
            try {
                $data=$this->formData($request,['store_id','_request_key','_csrf_token'],$csrf,'inventory_create');$store=$data['store_id']??'';$key=$data['_request_key']??'';
                $result=$this->workflow->create($actor,(object)['store_id'=>$store],$key);
                $this->addFlash('success','Le brouillon d’inventaire a été créé. Les mouvements restent autorisés jusqu’au démarrage.');
                return $this->redirectToRoute('inventories_detail',['id'=>$result['data']['id']],303);
            } catch(ApiProblem $problem) {$error=$problem->getMessage();$status=$problem->status;}
        }
        return $this->render('inventories/form.html.twig',['stores'=>$stores,'store_id'=>$store,'request_key'=>$key,'error'=>$error],new Response(status:$status));
    }
    #[Route('/inventaires/{id}',name:'inventories_detail',methods:['GET'])]
    public function detail(string $id,Request $request,QueryParameters $query): Response
    {
        $actor=$this->actor();$filters=$query->read($request,'listInventoryLines');$inventory=$this->inventories->get($actor,$id)['data'];$lines=$this->inventories->lines($actor,$id,$filters);
        return $this->render('inventories/detail.html.twig',['inventory'=>$inventory,'lines'=>$lines,'filters'=>$filters,'labels'=>$this->inventories->labels([$inventory],$lines['data']),'inventory_target'=>AccessTarget::inventory($id),'request_key'=>bin2hex(random_bytes(16))]);
    }
    #[Route('/inventaires/{id}/{action}',name:'inventories_transition',requirements:['action'=>'start|submit|cancel'],methods:['POST'])]
    public function transition(string $id,string $action,Request $request,CsrfTokenManagerInterface $csrf): Response
    {
        $actor=$this->actor();$data=$this->formData($request,['_request_key','_csrf_token'],$csrf,'inventory_'.$id.'_'.$action);
        $this->workflow->transition($actor,$id,$action,$data['_request_key']??'');
        $this->addFlash('success',match($action) {'start'=>'L’inventaire a démarré. Les mouvements du magasin sont bloqués.','submit'=>'L’inventaire attend sa validation. Le magasin reste bloqué.','cancel'=>'L’inventaire a été annulé. Les mouvements du magasin sont à nouveau autorisés.'});
        return $this->redirectToRoute('inventories_detail',['id'=>$id],303);
    }
    private function formData(Request $request,array $allowed,CsrfTokenManagerInterface $csrf,string $tokenId): array
    {
        if(strlen($request->getContent())>65536) {throw new ApiProblem(413,'payload_too_large','Le formulaire est trop volumineux.');}
        if($request->query->count()>0 || $request->files->count()>0) {throw new ApiProblem(422,'invalid_form','Le formulaire contient des paramètres supplémentaires.');}
        $data=$request->request->all();foreach($data as $name=>$value) {if(!in_array($name,$allowed,true) || !is_string($value)) {throw new ApiProblem(422,'invalid_form','Un champ du formulaire est invalide.');}}
        if(!$csrf->isTokenValid(new CsrfToken($tokenId,$data['_csrf_token']??''))) {throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page avant de réessayer.');}return $data;
    }
    private function actor(): StockUser
    {
        $actor=$this->getUser();if(!$actor instanceof StockUser) {throw new ApiProblem(401,'authentication_required','Connectez-vous.');}$this->identity->requireRole($actor,Permission::INVENTORY_READ);return $actor;
    }
}
