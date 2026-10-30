<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\CatalogDirectory;
use App\Administration\ProposalAdministration;
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

final class ProposalController extends AbstractController
{
    public function __construct(private readonly ProposalAdministration $proposals,private readonly CatalogDirectory $catalog,private readonly \App\Administration\AdminInbox $inbox) {}
    #[Route('/api/v1/article-proposals',name:'api_proposals_list',methods:['GET'])]
    public function listing(Request $request,QueryParameters $query): JsonResponse { return $this->json($this->proposals->listing($this->actor(),$query->read($request,'listProposals'))); }
    #[Route('/api/v1/article-proposals/{id}',name:'api_proposals_get',methods:['GET'])]
    public function get(string $id): JsonResponse { return $this->json($this->proposals->get($this->actor(),$id)); }
    #[Route('/api/v1/article-proposals',name:'api_proposals_create',methods:['POST'])]
    #[IsGranted('APP_CATALOG_PROPOSE')]
    public function submit(Request $request,JsonBody $json,IdempotencyKey $key): JsonResponse
    {
        $result=$this->proposals->submit($this->actor(),$json->read($request,'ProposalCreate'),$key->read($request));
        return $this->json($result,201,['Location'=>'/api/v1/article-proposals/'.$result['data']['id']]);
    }
    #[Route('/api/v1/article-proposals/{id}/review',name:'api_proposals_review',methods:['POST'])]
    #[IsGranted('APP_CATALOG_ADMIN')]
    public function review(string $id,Request $request,JsonBody $json,IdempotencyKey $key): JsonResponse { return $this->json($this->proposals->review($this->actor(),$id,$json->read($request,'ProposalReview'),$key->read($request))); }
    #[Route('/propositions',name:'proposals_list',methods:['GET'])]
    public function index(Request $request,QueryParameters $query): Response
    {
        $filtered=$request->duplicate();if($filtered->query->get('status')==='') { $filtered->query->remove('status'); }
        $filters=$query->read($filtered,'listProposals');return $this->render('proposals/list.html.twig',['result'=>$this->proposals->listing($this->actor(),$filters),'filters'=>$filters]);
    }
    #[Route('/propositions/nouvelle',name:'proposals_new',methods:['GET','POST'],priority:10, defaults: ['_camera_scan'=>true])]
    #[IsGranted('APP_CATALOG_PROPOSE')]
    public function form(Request $request,CsrfTokenManagerInterface $csrf): Response
    {
        $actor=$this->actor();$error=null;$status=200;$article=null;$key=bin2hex(random_bytes(16));
        $draft=['operation'=>'create','article_id'=>null,'base_version'=>null,'payload'=>['sku'=>'','name'=>'','description'=>'','category_id'=>null,'active'=>true,'scan_codes'=>[]]];
        if(!$request->isMethod('POST') && $request->query->has('article_id')) {
            $article=$this->catalog->get($actor,'article',$request->query->getString('article_id'))['data'];
            $draft=['operation'=>'update','article_id'=>$article['id'],'base_version'=>$article['version'],'payload'=>array_intersect_key($article,array_flip(['name','description','category_id','active','scan_codes']))];
        }
        if($request->isMethod('POST')) {
            try {
                if(!$csrf->isTokenValid(new CsrfToken('proposal_create',$request->request->getString('_csrf_token')))) { throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page avant de réessayer.'); }
                if(strlen($request->getContent())>65536) { throw new ApiProblem(413,'payload_too_large','Le formulaire est trop volumineux.'); }
                $key=$request->request->getString('_creation_key');$operation=$request->request->getString('operation');
                $payload=(object)['name'=>$request->request->getString('name'),'description'=>$request->request->getString('description'),'category_id'=>$request->request->getString('category_id')?:null,'active'=>$request->request->getBoolean('active')];
                $payload->scan_codes=\App\Access\ArticleCodes::lines($request->request->getString('scan_codes'));
                $body=(object)['operation'=>$operation,'payload'=>$payload];
                if($operation==='update') { $body->article_id=$request->request->getString('article_id');$version=$request->request->getString('base_version');if(!preg_match('/^[1-9][0-9]{0,15}$/D',$version)) { throw new ApiProblem(422,'invalid_version','Rechargez l’article avant de proposer une modification.'); }$body->base_version=(int)$version; }
                else { $payload->sku=$request->request->getString('sku');$payload->unit_code='unit'; }
                $draft=['operation'=>$operation,'article_id'=>$body->article_id??null,'base_version'=>$body->base_version??null,'payload'=>get_object_vars($payload)];
                $result=$this->proposals->submit($actor,$body,$key);$this->addFlash('success','La proposition a été envoyée. Le catalogue reste inchangé jusqu’à son approbation.');return $this->redirectToRoute('proposals_detail',['id'=>$result['data']['id']],303);
            } catch(ApiProblem $problem) { $error=$problem->getMessage();$status=$problem->status; }
        }
        if($article===null&&$draft['article_id']!==null) { try { $article=$this->catalog->get($actor,'article',$draft['article_id'])['data']; }catch(ApiProblem) {} }
        return $this->render('proposals/form.html.twig',['draft'=>$draft,'article'=>$article,'categories'=>$this->catalog->categoryOptions($actor),'creation_key'=>$key,'scan_codes_text'=>$request->isMethod('POST')?$request->request->getString('scan_codes'):implode("\n",$draft['payload']['scan_codes']??[]),'error'=>$error],new Response(status:$status));
    }
    #[Route('/propositions/{id}',name:'proposals_detail',methods:['GET'])]
    public function detail(string $id): Response { return $this->renderDetail($id); }
    #[Route('/propositions/{id}/decision',name:'proposals_decide',methods:['POST'])]
    #[IsGranted('APP_CATALOG_ADMIN')]
    public function decide(string $id,Request $request,CsrfTokenManagerInterface $csrf): Response
    {
        $key=$request->request->getString('_review_key');$comment=$request->request->getString('comment');
        try {
            if(!$csrf->isTokenValid(new CsrfToken('proposal_review_'.$id,$request->request->getString('_csrf_token')))) { throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page avant de réessayer.'); }
            if(strlen($request->getContent())>65536) { throw new ApiProblem(413,'payload_too_large','Le formulaire est trop volumineux.'); }
            $this->proposals->review($this->actor(),$id,(object)['decision'=>$request->request->getString('decision'),'comment'=>$comment],$key);
            $this->addFlash('success','La décision a été enregistrée.');return $this->redirectToRoute('proposals_detail',['id'=>$id],303);
        } catch(ApiProblem $problem) { return $this->renderDetail($id,$problem->getMessage(),$problem->status,$key,$comment); }
    }
    private function renderDetail(string $id,?string $error=null,int $status=200,?string $key=null,string $comment=''): Response
    {
        $actor=$this->actor();$context=$this->proposals->context($actor,$id);
        if($actor->role==='ADMIN') {$this->inbox->read($actor,'article_proposal',$id);$this->container->get('twig')->addGlobal('admin_unread_count',$this->inbox->count($actor));}
        return $this->render('proposals/detail.html.twig',$context+['categories'=>$this->catalog->categoryOptions($actor),'error'=>$error,'review_key'=>$key??bin2hex(random_bytes(16)),'comment'=>$comment],new Response(status:$status));
    }
    private function actor(): StockUser { $actor=$this->getUser();if(!$actor instanceof StockUser) { throw new ApiProblem(401,'authentication_required','Connectez-vous.'); }return $actor; }
}
