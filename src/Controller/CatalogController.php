<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\CatalogDirectory;
use App\Administration\CatalogAdministration;
use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\JsonBody;
use App\Api\QueryParameters;
use App\Security\StockUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CatalogController extends AbstractController
{
    public function __construct(private readonly CatalogDirectory $directory,private readonly CatalogAdministration $administration) {}
    #[Route('/api/v1/articles',name:'api_articles_list',methods:['GET'],defaults:['kind'=>'article'])]
    #[Route('/api/v1/categories',name:'api_categories_list',methods:['GET'],defaults:['kind'=>'category'])]
    public function listing(string $kind,Request $request,QueryParameters $query): JsonResponse
    {
        return $this->json($this->directory->listing($this->actor(),$kind,$query->read($request,$kind==='article'?'listArticles':'listCategories')));
    }
    #[Route('/api/v1/articles/resolve-code',name:'api_articles_resolve_code',priority:20,methods:['POST'])]
    public function resolveCode(Request $request, JsonBody $json): JsonResponse
    {
        $body = $json->read($request, 'ArticleCodeLookup');
        return $this->json($this->directory->resolveCode($this->actor(), $body->code));
    }
    #[Route('/api/v1/articles/{id}',name:'api_articles_get',methods:['GET'])]
    public function article(string $id): JsonResponse { return $this->json($this->directory->get($this->actor(),'article',$id)); }
    #[Route('/api/v1/articles',name:'api_articles_create',methods:['POST'],defaults:['kind'=>'article'])]
    #[Route('/api/v1/categories',name:'api_categories_create',methods:['POST'],defaults:['kind'=>'category'])]
    #[IsGranted('APP_CATALOG_ADMIN')]
    public function create(string $kind,Request $request,JsonBody $json,IdempotencyKey $key): JsonResponse
    {
        $result=$this->administration->create($this->actor(),$kind,$json->read($request,$kind==='article'?'ArticleCreate':'CategoryCreate'),$key->read($request));
        $path=$kind==='article'?'/api/v1/articles/':'/api/v1/categories/';
        return $this->json($result,201,['Location'=>$path.$result['data']['id']]);
    }
    #[Route('/api/v1/articles/{id}',name:'api_articles_update',methods:['PATCH'],defaults:['kind'=>'article'])]
    #[Route('/api/v1/categories/{id}',name:'api_categories_update',methods:['PATCH'],defaults:['kind'=>'category'])]
    #[IsGranted('APP_CATALOG_ADMIN')]
    public function update(string $kind,string $id,Request $request,JsonBody $json): JsonResponse
    {
        return $this->json($this->administration->update($this->actor(),$kind,$id,$json->read($request,$kind==='article'?'ArticlePatch':'CategoryPatch')));
    }
    #[Route('/articles',name:'catalog_list',methods:['GET'],defaults:['kind'=>'article'])]
    #[Route('/categories',name:'catalog_categories',methods:['GET'],defaults:['kind'=>'category'])]
    public function index(string $kind,Request $request,QueryParameters $query): Response
    {
        $filtered=$request->duplicate();foreach (['status','q','category_id'] as $name) { if ($filtered->query->get($name)==='') { $filtered->query->remove($name); } }
        $filters=$query->read($filtered,$kind==='article'?'listArticles':'listCategories');
        return $this->render('catalog/list.html.twig',['kind'=>$kind,'result'=>$this->directory->listing($this->actor(),$kind,$filters),'filters'=>$filters,'categories'=>$this->directory->categoryOptions($this->actor())]);
    }
    #[Route('/articles/{id}',name:'catalog_article',methods:['GET'])]
    public function detail(string $id,Request $request,QueryParameters $query,\App\Access\StockDirectory $stocks): Response
    {
        $filtered=$request->duplicate();if ($filtered->query->get('store_id')==='') { $filtered->query->remove('store_id'); }
        $filters=$query->read($filtered,'articleStocks');$stock=$stocks->article($this->actor(),$id,$filters)['data'];
        return $this->render('catalog/detail.html.twig',['stock_summary'=>$stock,'stock_labels'=>$stocks->labels($stock['stores']['data']),'stock_filters'=>$filters,'article'=>$this->directory->get($this->actor(),'article',$id)['data'],'categories'=>$this->directory->categoryOptions($this->actor())]);
    }
    #[Route('/administration/articles/nouveau',name:'catalog_article_new',methods:['GET','POST'],defaults:['kind'=>'article','_camera_scan'=>true])]
    #[Route('/administration/articles/{id}/modifier',name:'catalog_article_edit',methods:['GET','POST'],defaults:['kind'=>'article','_camera_scan'=>true])]
    #[Route('/administration/categories/nouvelle',name:'catalog_category_new',methods:['GET','POST'],defaults:['kind'=>'category'])]
    #[Route('/administration/categories/{id}/modifier',name:'catalog_category_edit',methods:['GET','POST'],defaults:['kind'=>'category'])]
    #[IsGranted('APP_CATALOG_ADMIN')]
    public function form(string $kind,Request $request,CsrfTokenManagerInterface $csrf,?string $id=null): Response
    {
        $actor=$this->actor();$isNew=$id===null;
        $data=$isNew?($kind==='article'?['sku'=>'','name'=>'','description'=>'','category_id'=>null,'active'=>true,'unit_code'=>'unit','version'=>1]:['name'=>'','parent_id'=>null,'active'=>true]):$this->administration->get($actor,$kind,$id)['data'];
        $key=$isNew?bin2hex(random_bytes(16)):'';$tokenId='catalog_'.$kind.'_'.($id??'create');$error=null;$status=200;
        if ($request->isMethod('POST')) {
            try {
                if (!$csrf->isTokenValid(new CsrfToken($tokenId,$request->request->getString('_csrf_token')))) { throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page avant de réessayer.'); }
                if (strlen($request->getContent())>65536) { throw new ApiProblem(413,'payload_too_large','Le formulaire est trop volumineux.'); }
                $key=$isNew?$request->request->getString('_creation_key'):'';
                $body=(object)['name'=>$request->request->getString('name')];
                if ($kind==='article') {
                    $body->scan_codes=\App\Access\ArticleCodes::lines($request->request->getString('scan_codes'));
                    $body->description=$request->request->getString('description');$body->category_id=$request->request->getString('category_id')?:null;
                    if ($isNew) { $body->sku=$request->request->getString('sku'); }
                    else {
                        $version=$request->request->getString('version');if (!preg_match('/^[1-9][0-9]{0,15}$/D',$version)) { throw new ApiProblem(422,'invalid_version','Version de formulaire invalide. Rechargez la fiche.'); }
                        $body->version=(int)$version;
                    }
                } else { $body->parent_id=$request->request->getString('parent_id')?:null; }
                $body->active=$request->request->getBoolean('active');
                $data=array_replace($data,get_object_vars($body));
                $result=$isNew?$this->administration->create($actor,$kind,$body,$key):$this->administration->update($actor,$kind,$id,$body);
                $this->addFlash('success',$isNew?($kind==='article'?'L’article a été créé.':'La catégorie a été créée.'):'Les modifications ont été enregistrées.');
                return $this->redirectToRoute($kind==='article'?'catalog_article_edit':'catalog_category_edit',['id'=>$result['data']['id']],303);
            } catch (ApiProblem $problem) { $error=$problem->getMessage();$status=$problem->status; }
        }
        return $this->render('catalog/form.html.twig',['kind'=>$kind,'item'=>$data,'is_new'=>$isNew,'error'=>$error,'token_id'=>$tokenId,'creation_key'=>$key,'scan_codes_text'=>$request->isMethod('POST')?$request->request->getString('scan_codes'):implode("\n",$data['scan_codes']??[]),'categories'=>$this->directory->categoryOptions($actor)],new Response(status:$status));
    }
    private function actor(): StockUser { $actor=$this->getUser();if (!$actor instanceof StockUser) { throw new ApiProblem(401,'authentication_required','Connectez-vous.'); }return $actor; }
}
