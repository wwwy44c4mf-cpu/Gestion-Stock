<?php

declare(strict_types=1);
namespace App\Controller;
use App\Api\{ApiProblem,JsonBody};
use App\Location\ArticleLocations;
use App\Security\StockUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\{CsrfToken,CsrfTokenManagerInterface};
final class ArticleLocationController extends AbstractController
{
    public function __construct(private readonly ArticleLocations $locations,private readonly \App\Access\AccessDirectory $directory,
        private readonly \App\Security\AccessIdentity $identity,private readonly \Doctrine\DBAL\Connection $db) {}
    #[Route('/casages',name:'stocks_locations',methods:['GET'],defaults:['_camera_scan'=>true])]
    public function index(Request $request,\App\Ui\StoreContext $context): Response
    {
        try {
        $actor=$this->actor();$this->identity->requireRole($actor,\App\Security\Permission::LOCATION_WRITE);
        $stores=$this->directory->storeOptions($actor,\App\Security\Permission::LOCATION_WRITE);
        $articles=$this->db->fetchAllAssociative('SELECT id,sku,name,active FROM articles ORDER BY name,id');
        $item=['store_id'=>$context->preferred($request,$actor,$stores)??'','article_id'=>''];
        foreach($request->query->all() as $name=>$value) {
            if(!array_key_exists($name,$item)||!is_string($value)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$value)) {throw new ApiProblem(422,'invalid_query','Présélection invalide.');}
            $item[$name]=strtolower($value);
        }
        foreach(['store_id'=>$stores,'article_id'=>$articles] as $key=>$options) {if($item[$key]!==''&&!in_array($item[$key],array_column($options,'id'),true)) {throw new ApiProblem(404,'resource_not_found','Ressource introuvable.');}}
        } catch(ApiProblem $e) {throw new \Symfony\Component\HttpKernel\Exception\HttpException($e->status,$e->getMessage(),$e);}
        return $this->render('stocks/locations.html.twig',['stores'=>$stores,'articles'=>$articles,'item'=>$item]);
    }
    #[Route('/api/v1/stores/{storeId}/articles/{articleId}/location',name:'api_location_get',methods:['GET'])]
    public function get(string $storeId,string $articleId): JsonResponse {return $this->json($this->locations->get($this->actor(),$storeId,$articleId));}
    #[Route('/api/v1/stores/{storeId}/articles/{articleId}/location',name:'api_location_update',methods:['PATCH'])]
    public function update(string $storeId,string $articleId,Request $request,JsonBody $json): JsonResponse
    {
        $body=$json->read($request,'LocationUpdate');return $this->json($this->locations->save($this->actor(),$storeId,$articleId,$body->location,$body->version));
    }
    #[Route('/stocks/{storeId}/{articleId}/casage',name:'stocks_location_edit',methods:['GET','POST'])]
    public function form(string $storeId,string $articleId,Request $request,CsrfTokenManagerInterface $csrf): Response
    {
        try {
        $actor=$this->actor();$item=$this->locations->get($actor,$storeId,$articleId)['data'];
        if(!$item['can_edit']) {throw new ApiProblem(403,'permission_denied','Seuls les administrateurs et gestionnaires autorisés peuvent modifier ce casage.');}
        } catch(ApiProblem $e) {throw new \Symfony\Component\HttpKernel\Exception\HttpException($e->status,$e->getMessage(),$e);}
        $error=null;$status=200;
        if($request->isMethod('POST')) {
            try {
                if(strlen($request->getContent())>65536||$request->query->count()||$request->files->count()) {throw new ApiProblem(422,'invalid_form','Formulaire invalide.');}
                if(!$csrf->isTokenValid(new CsrfToken('location_'.$storeId.'_'.$articleId,$request->request->getString('_csrf_token')))) {throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page.');}
                $version=$request->request->getString('version');if(!preg_match('/^(0|[1-9][0-9]{0,15})$/D',$version)) {throw new ApiProblem(422,'invalid_location_version','Rechargez la valeur actuelle.');}
                $this->locations->save($actor,$storeId,$articleId,$request->request->getString('location'),(int)$version);
                $this->addFlash('success','Le casage de cet article a été enregistré pour ce magasin.');return $this->redirectToRoute('stocks_location_edit',['storeId'=>$storeId,'articleId'=>$articleId],303);
            } catch(ApiProblem $e) {$error=$e->getMessage();$status=$e->status;}
        }
        $labels=$this->db->fetchAssociative('SELECT a.sku,a.name article_name,s.code store_code,s.name store_name FROM articles a CROSS JOIN stores s WHERE a.id=? AND s.id=?',[$articleId,$storeId]);
        return $this->render('stocks/location.html.twig',['item'=>$item,'labels'=>$labels,'error'=>$error],new Response(status:$status));
    }
    private function actor(): StockUser {$user=$this->getUser();if(!$user instanceof StockUser) {throw new ApiProblem(401,'authentication_required','Connectez-vous.');}return $user;}
}
