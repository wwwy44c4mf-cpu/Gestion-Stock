<?php
declare(strict_types=1);
namespace App\Controller;
use App\Api\ApiProblem;
use App\Security\StockUser;
use App\Stock\StoreArticleWithdrawal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\{CsrfToken,CsrfTokenManagerInterface};
final class StoreArticleWithdrawalController extends AbstractController
{
    #[Route('/stocks/{storeId}/{articleId}/retirer',name:'stocks_article_withdraw',methods:['GET','POST'])]
    public function form(string $storeId,string $articleId,Request $request,StoreArticleWithdrawal $service,CsrfTokenManagerInterface $csrf): Response
    {
        $actor=$this->getUser();if(!$actor instanceof StockUser) {throw new ApiProblem(401,'authentication_required','Connectez-vous.');}
        try {$item=$service->preview($actor,$storeId,$articleId);}
        catch(ApiProblem $e) {throw new \Symfony\Component\HttpKernel\Exception\HttpException($e->status,$e->getMessage(),$e);}
        $error=null;$status=200;
        if($request->isMethod('POST')) {
            try {
                if(strlen($request->getContent())>65536||$request->query->count()||$request->files->count()) {throw new ApiProblem(422,'invalid_form','Formulaire invalide.');}
                if(!$csrf->isTokenValid(new CsrfToken('withdraw_'.$storeId.'_'.$articleId,$request->request->getString('_csrf_token')))) {throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page.');}
                if($request->request->getString('confirm')!=='1') {throw new ApiProblem(422,'confirmation_required','Confirmez le retrait de ce magasin.');}
                $service->withdraw($actor,$storeId,$articleId);
                $this->addFlash('success','L’article a été retiré de ce magasin. Son historique, sa fiche et les autres magasins sont conservés.');
                return $this->redirectToRoute('stocks_list',['store_id'=>$storeId],303);
            } catch(ApiProblem $e) {$error=$e->getMessage();$status=$e->status;}
        }
        return $this->render('stocks/withdraw.html.twig',['item'=>$item,'error'=>$error],new Response(status:$status));
    }
}
