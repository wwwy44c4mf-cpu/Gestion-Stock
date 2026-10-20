<?php

declare(strict_types=1);
namespace App\Controller;
use App\Access\StockDirectory;
use App\Api\ApiProblem;
use App\Api\JsonBody;
use App\Security\StockUser;
use App\Stock\StockThresholds;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class StockThresholdController extends AbstractController
{
    public function __construct(private readonly StockThresholds $thresholds,private readonly StockDirectory $stocks) {}
    #[Route('/api/v1/stores/{storeId}/stocks/{articleId}/thresholds',name:'api_stock_thresholds_update',methods:['PATCH'])]
    public function update(string $storeId,string $articleId,Request $request,JsonBody $json): JsonResponse
    {
        return $this->json($this->thresholds->update($this->actor(),$storeId,$articleId,$json->read($request,'ThresholdPatch')));
    }
    #[Route('/stocks/{storeId}/{articleId}/seuils',name:'stocks_thresholds',methods:['GET','POST'])]
    public function form(string $storeId,string $articleId,Request $request,CsrfTokenManagerInterface $csrf): Response
    {
        $actor=$this->actor();$stock=$this->thresholds->get($actor,$storeId,$articleId)['data'];$storeId=$stock['store_id'];$articleId=$stock['article_id'];
        $item=['low_threshold'=>$stock['low_threshold']===null?'':(string)$stock['low_threshold'],'critical_threshold'=>$stock['critical_threshold']===null?'':(string)$stock['critical_threshold']];
        $revision=$this->thresholds->revision($stock);$error=null;$status=200;
        if($request->query->count()>0) {throw new ApiProblem(422,'invalid_query','Aucun paramètre de recherche n’est accepté.');}
        if($request->isMethod('POST')) {
            try {
                if(strlen($request->getContent())>65536) {throw new ApiProblem(413,'payload_too_large','Le formulaire est trop volumineux.');}
                if($request->files->count()>0) {throw new ApiProblem(422,'invalid_form','Aucun fichier n’est accepté.');}$raw=$request->request->all();
                foreach($raw as $name=>$value) {if(!in_array($name,['_csrf_token','_threshold_revision','low_threshold','critical_threshold'],true) || !is_string($value)) {throw new ApiProblem(422,'invalid_form','Un champ du formulaire est invalide.');}}
                foreach($item as $name=>$_) {$item[$name]=$raw[$name]??'';}$revision=$raw['_threshold_revision']??'';
                if(!$csrf->isTokenValid(new CsrfToken('stock_thresholds_'.$storeId.'_'.$articleId,$raw['_csrf_token']??''))) {throw new ApiProblem(403,'invalid_csrf_token','Formulaire expiré. Rechargez la page.');}
                $values=[];foreach($item as $name=>$value) {if($value==='') {$values[$name]=null;continue;}if(!preg_match('/^(0|[1-9][0-9]{0,15})$/D',$value) || (int)$value>9007199254740991) {throw new ApiProblem(422,'invalid_thresholds','Saisissez des nombres entiers positifs ou zéro, sans décimale.');}$values[$name]=(int)$value;}
                $this->thresholds->update($actor,$storeId,$articleId,(object)$values,$revision);$this->addFlash('success','Les seuils de cet article ont été enregistrés pour ce magasin.');
                return $this->redirectToRoute('stocks_detail',['storeId'=>$storeId,'articleId'=>$articleId],303);
            } catch(ApiProblem $problem) {$error=$problem->getMessage();$status=$problem->status;}
        }
        return $this->render('stocks/thresholds.html.twig',['stock'=>$stock,'labels'=>$this->stocks->labels([$stock]),'item'=>$item,'revision'=>$revision,'error'=>$error],new Response(status:$status));
    }
    private function actor(): StockUser
    {
        $actor=$this->getUser();if(!$actor instanceof StockUser) {throw new ApiProblem(401,'authentication_required','Connectez-vous.');}return $actor;
    }
}
