<?php

declare(strict_types=1);
namespace App\Controller;

use App\Administration\{AccountRequests,UserAdministration,AdminInbox};
use App\Api\{ApiProblem,JsonBody};
use App\Security\StockUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse};
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\{CsrfToken,CsrfTokenManagerInterface};
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AccountRequestController extends AbstractController
{
    public function __construct(private readonly AccountRequests $requests,private readonly UserAdministration $users,
        private readonly CsrfTokenManagerInterface $csrf,private readonly AdminInbox $inbox,
        #[Autowire(service:'limiter.api_sensitive')] private readonly RateLimiterFactory $limiter) {}

    #[Route('/api/v1/auth/account-requests',name:'api_account_request',methods:['POST'],defaults:['_api_sensitive'=>true])]
    public function api(Request $request,JsonBody $json): JsonResponse
    {
        $this->requests->request($json->read($request,'AccountRequestCreate'));
        return $this->json(['message'=>AccountRequests::ACCEPTED],202);
    }

    #[Route('/demander-un-compte',name:'account_request',methods:['GET','POST'])]
    public function form(Request $request): Response
    {
        $error=null;$accepted=false;$status=200;
        $data=['first_name'=>'','last_name'=>'','email'=>''];
        if($request->isMethod('POST')) {
            try {
                $this->protect($request,'account_request',true);
                foreach($data as $key=>$unused) {$data[$key]=$request->request->getString($key);}
                $this->requests->request((object)$data);$accepted=true;$data=array_fill_keys(array_keys($data),'');
            } catch(ApiProblem $e) {$error=$e->getMessage();$status=$e->status;}
        }
        return $this->render('security/account_request.html.twig',['error'=>$error,'accepted'=>$accepted,'data'=>$data,'message'=>AccountRequests::ACCEPTED],new Response(status:$status));
    }

    #[Route('/administration/demandes-comptes',name:'admin_users_requests',methods:['GET'])]
    #[IsGranted('APP_ACCOUNTS_ADMIN')]
    public function index(Request $request): Response
    {
        try {
            $page=filter_var($request->query->get('page','1'),FILTER_VALIDATE_INT);
            if($page===false) {throw new ApiProblem(422,'invalid_pagination','Pagination invalide.');}
            return $this->render('admin/account_requests.html.twig',['result'=>$this->requests->listing($this->actor(),$page)]);
        } catch(ApiProblem $e) {return $this->render('admin/error.html.twig',['message'=>$e->getMessage()],new Response(status:$e->status));}
    }

    #[Route('/administration/demandes-comptes/{id}',name:'admin_users_requests_review',methods:['GET','POST'])]
    #[IsGranted('APP_ACCOUNTS_ADMIN')]
    public function review(string $id,Request $request): Response
    {
        $error=null;$status=200;$actor=$this->actor();
        try {$data=$this->requests->get($actor,$id);}
        catch(ApiProblem $e) {return $this->render('admin/error.html.twig',['message'=>$e->getMessage()],new Response(status:$e->status));}
        if($request->isMethod('GET')) {$this->inbox->read($actor,'account_request',$id);}
        $this->container->get('twig')->addGlobal('admin_unread_count',$this->inbox->count($actor));
        $choices=['role'=>'USER','store_ids'=>[]];
        if($request->isMethod('POST')) {
            try {
                $this->protect($request,'account_request_review_'.$id);
                $action=$request->request->getString('decision');
                if($action==='approve') {$choices=['role'=>$request->request->getString('role'),'store_ids'=>array_values($request->request->all('store_ids'))];}
                $this->requests->decide($actor,$id,$action,(object)($action==='approve'?$choices:[]));
                $this->addFlash('success',match($action) {'approve'=>'Demande approuvée. Un lien de création du mot de passe est prévu par courriel.','reject'=>'Demande refusée.',default=>'Invitation prévue par courriel. Un renvoi récent est conservé pour éviter les doublons.'});
                return $this->redirectToRoute('admin_users_requests_review',['id'=>$id],303);
            } catch(ApiProblem $e) {$error=$e->getMessage();$status=$e->status;}
        }
        return $this->render('admin/account_request_review.html.twig',['account_request'=>$data,'choices'=>$choices,'stores'=>$this->users->storeOptions($actor),'error'=>$error],new Response(status:$status));
    }
    private function protect(Request $request,string $id,bool $limited=false): void
    {
        if(strlen($request->getContent())>65536) {throw new ApiProblem(413,'payload_too_large','Formulaire trop volumineux.');}
        if(!$this->csrf->isTokenValid(new CsrfToken($id,$request->request->getString('_csrf_token')))) {throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page.');}
        if($limited&&!$this->limiter->create(hash('sha256',$request->getClientIp().'|api_account_request'))->consume()->isAccepted()) {throw new ApiProblem(429,'request_throttled','Trop de tentatives. Réessayez dans une minute.');}
    }
    private function actor(): StockUser
    {
        $user=$this->getUser();if(!$user instanceof StockUser) {throw new ApiProblem(401,'authentication_required','Connectez-vous.');}return $user;
    }
}
