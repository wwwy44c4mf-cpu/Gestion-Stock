<?php

declare(strict_types=1);
namespace App\Controller;
use App\Administration\ArticlePurge;
use App\Api\ApiProblem;
use App\Security\StockUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\{CsrfToken,CsrfTokenManagerInterface};
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('APP_ARTICLE_PURGE')]
final class ArticlePurgeController extends AbstractController
{
    public function __construct(private readonly ArticlePurge $cleanup) {}
    #[Route('/articles/suppression-definitive',name:'article_purge_list',methods:['GET'],priority:20,defaults:['section'=>'articles'])]
    public function index(Request $request): Response
    {
        try {$q=$request->query->getString('q');$rows=$this->cleanup->listing($this->actor(),$q);}
        catch(ApiProblem $e) {throw new HttpException($e->status,$e->getMessage(),$e);}
        return $this->render('admin/article_purge_list.html.twig',['articles'=>array_slice($rows,0,50),'more'=>count($rows)>50,'q'=>$q]);
    }
    #[Route('/articles/{id}/effacer-definitivement',name:'article_purge_confirm',methods:['GET','POST'],defaults:['section'=>'articles'])]
    public function confirm(string $id,Request $request,CsrfTokenManagerInterface $csrf): Response
    {
        $actor=$this->actor();$error=null;$status=200;
        if($request->isMethod('POST')) {
            try {
                if(strlen($request->getContent())>16384||$request->files->count()) {throw new ApiProblem(413,'payload_too_large','Formulaire trop volumineux.');}
                foreach($request->request->all() as $name=>$value) {if(!in_array($name,['_csrf_token','preview_token','preview_at','sku','confirmed'],true)||!is_string($value)) {throw new ApiProblem(422,'invalid_form','Formulaire invalide.');}}
                if(!$csrf->isTokenValid(new CsrfToken('article_purge_'.$id,$request->request->getString('_csrf_token')))) {throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page.');}
                $at=$request->request->getString('preview_at');$token=$request->request->getString('preview_token');
                if(!preg_match('/^[0-9]{10}$/D',$at)||!preg_match('/^[a-f0-9]{64}$/D',$token)) {throw new ApiProblem(422,'invalid_preview','Rechargez le récapitulatif avant de confirmer.');}
                $this->cleanup->purge($actor,$id,$token,(int)$at,$request->request->getString('sku'),$request->request->getString('confirmed')==='1');
                $this->addFlash('success','L’article, ses stocks et son historique ont été effacés dans tous les magasins. Les autres articles sont conservés.');
                return $this->redirectToRoute('article_purge_list',[],303);
            } catch(ApiProblem $e) {$error=$e->getMessage();$status=$e->status;}
        }
        try {$preview=$this->cleanup->preview($actor,$id);}
        catch(ApiProblem $e) {throw new HttpException($e->status,$e->getMessage(),$e);}
        return $this->render('admin/article_purge.html.twig',['preview'=>$preview,'error'=>$error],new Response(status:$status));
    }
    private function actor(): StockUser {$user=$this->getUser();if(!$user instanceof StockUser) {throw $this->createAccessDeniedException();}return $user;}
}
