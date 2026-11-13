<?php

declare(strict_types=1);
namespace App\Controller;
use App\Access\{AccessDirectory,CatalogDirectory};
use App\Api\ApiProblem;
use App\Curatif\{ToolLending,CableReels,StockExtensions};
use App\Security\{AccessPolicy,AccessTarget,Permission,StockUser};
use App\Ui\StoreContext;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\{CsrfToken,CsrfTokenManagerInterface};
final class CuratifController extends AbstractController
{
    public function __construct(private readonly Connection $db,private readonly AccessDirectory $directory,private readonly AccessPolicy $policy,
        private readonly ToolLending $tools,private readonly CableReels $reels,private readonly StockExtensions $extensions,private readonly StoreContext $context) {}
    #[Route('/consommations/outillage-ca',name:'ca_tools',methods:['GET','POST'],priority:20,defaults:['section'=>'consommations'])]
    public function tools(Request $request,CsrfTokenManagerInterface $csrf): Response
    {
        $actor=$this->actor();$stores=$this->directory->storeOptions($actor,Permission::CONSUMPTION_CREATE);$store=$this->store($request,$actor,$stores);$error=null;$status=200;
        if($request->isMethod('POST')) {try {
            $this->csrf($request,$csrf,'ca_tools');$action=$request->request->getString('action');
            if($action==='borrow') {$ids=$request->request->all('tools');foreach($ids as $id) {if(!is_string($id)) {throw new ApiProblem(422,'invalid_tool','Sélection invalide.');}}$this->tools->borrow($actor,$store,$ids,$request->request->getString('request_key'));}
            elseif($action==='return_all') {$this->tools->giveBackAll($actor,$store,$request->request->getString('borrower_id'),$request->request->all('loans'));}
            elseif($action==='add') {$this->tools->add($actor,$store,$request->request->getString('name'),$request->request->getString('reference'),$this->integer($request->request->getString('copies')));}
            elseif($action==='remove') {$this->tools->remove($actor,$store,$request->request->getString('tool_id'));}
            else {throw new ApiProblem(422,'invalid_action','Action inconnue.');}
            $this->addFlash('success','L’outillage a été mis à jour.');return $this->redirectToRoute('ca_tools',['store_id'=>$store],303);
        } catch(ApiProblem $e) {$error=$e->getMessage();$status=$e->status;}catch(\Doctrine\DBAL\Exception $e) {$error='Une référence existe déjà ou les outils ont changé. Rechargez la liste.';$status=409;}}
        try {$items=$store!==''?$this->tools->items($actor,$store):[];}catch(ApiProblem $e) {throw new HttpException($e->status,$e->getMessage(),$e);}
        $borrowers=[];foreach($items as $item) {if($item['loan_id']) {$borrowers[$item['borrower_id']]['name']=$item['borrower_name'];$borrowers[$item['borrower_id']]['items'][]=$item;}}
        return $this->render('curatif/tools.html.twig',['borrowers'=>$borrowers,'stores'=>$stores,'store_id'=>$store,'items'=>$items,'can_manage'=>in_array($actor->role,['ADMIN','MANAGER'],true),'error'=>$error,'request_key'=>bin2hex(random_bytes(16))],new Response(status:$status));
    }
    #[Route('/consommations/tourets',name:'ca_reels',methods:['GET','POST'],priority:20,defaults:['section'=>'consommations'])]
    public function reels(Request $request,CsrfTokenManagerInterface $csrf): Response
    {
        $actor=$this->actor();$stores=$this->directory->storeOptions($actor,Permission::CONSUMPTION_CREATE);$store=$this->store($request,$actor,$stores);$error=null;$status=200;
        if($request->isMethod('POST')) {try {
            $this->csrf($request,$csrf,'ca_reels');$action=$request->request->getString('action');$length=$this->integer($request->request->getString('length'));$key=$request->request->getString('request_key');
            if($action==='receive') {$this->reels->receive($actor,$store,$request->request->getString('article_id'),$request->request->getString('reference'),$length,$key);}
            elseif($action==='use') {$this->reels->useLength($actor,$store,$request->request->getString('reel_id'),$length,$key);}
            else {throw new ApiProblem(422,'invalid_action','Action inconnue.');}
            $this->addFlash('success','La longueur du touret a été mise à jour.');return $this->redirectToRoute('ca_reels',['store_id'=>$store],303);
        }catch(ApiProblem $e) {$error=$e->getMessage();$status=$e->status;}catch(\Doctrine\DBAL\Exception $e) {$error='Cette référence existe déjà ou le touret a changé. Rechargez la liste.';$status=409;}}
        try {$items=$store!==''?$this->reels->listing($actor,$store):[];}catch(ApiProblem $e) {throw new HttpException($e->status,$e->getMessage(),$e);}
        $articles=$this->db->fetchAllAssociative('SELECT a.id,a.name,a.sku FROM articles a JOIN categories c ON c.id=a.category_id WHERE c.tracks_reels AND a.active AND a.deleted_at IS NULL ORDER BY a.name');
        return $this->render('curatif/reels.html.twig',['stores'=>$stores,'store_id'=>$store,'items'=>$items,'articles'=>$articles,'can_manage'=>in_array($actor->role,['ADMIN','MANAGER'],true),'error'=>$error,'request_key'=>bin2hex(random_bytes(16))],new Response(status:$status));
    }
    #[Route('/consommations/retour-sans-sortie',name:'unlinked_return_new',methods:['GET'],priority:20,defaults:['section'=>'consommations'])]
    public function deposit(): Response {return $this->redirectToRoute('consumptions_return_batch_new',[],303);}
    #[Route('/stocks/{storeId}/{articleId}/commande',name:'stock_order_update',methods:['POST'],defaults:['section'=>'stocks'])]
    public function order(string $storeId,string $articleId,Request $request,CsrfTokenManagerInterface $csrf): Response
    {
        try {$this->csrf($request,$csrf,'order_'.$storeId.'_'.$articleId);$this->extensions->order($this->actor(),$storeId,$articleId,$request->request->getString('ordered')==='1');}
        catch(ApiProblem $e) {throw new HttpException($e->status,$e->getMessage(),$e);}
        return $this->redirectToRoute('stocks_detail',['storeId'=>$storeId,'articleId'=>$articleId],303);
    }
    private function store(Request $request,StockUser $actor,array $stores): string
    {
        $id=$request->isMethod('POST')?$request->request->getString('store_id'):$request->query->getString('store_id');
        if($id==='') {$id=$this->context->preferred($request,$actor,$stores)??($stores[0]['id']??'');}
        if($id!==''&&!in_array($id,array_column($stores,'id'),true)) {throw new HttpException(404,'Magasin inaccessible.');}return $id;
    }
    private function csrf(Request $request,CsrfTokenManagerInterface $csrf,string $id): void
    {
        if(strlen($request->getContent())>32768||$request->files->count()) {throw new ApiProblem(413,'payload_too_large','Formulaire trop volumineux.');}
        if(!$csrf->isTokenValid(new CsrfToken($id,$request->request->getString('_csrf_token')))) {throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page.');}
    }
    private function integer(string $value): int {if(!preg_match('/^[1-9][0-9]{0,15}$/D',$value)||(int)$value>9007199254740991) {throw new ApiProblem(422,'invalid_quantity','Saisissez un entier positif.');}return (int)$value;}
    private function actor(): StockUser {$user=$this->getUser();if(!$user instanceof StockUser) {throw $this->createAccessDeniedException();}$this->policy->assert($user,Permission::CATALOG_READ);return $user;}
}
