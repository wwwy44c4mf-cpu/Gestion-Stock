<?php

declare(strict_types=1);
namespace App\Controller;
use App\Access\CatalogDirectory;
use App\Api\{ApiProblem,QueryParameters};
use App\Consumption\ConsumptionHistory;
use App\Security\StockUser;
use App\Ui\StoreContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse,Request,Response};
use Symfony\Component\Routing\Attribute\Route;
final class ConsumptionHistoryController extends AbstractController
{
    public function __construct(private readonly ConsumptionHistory $history) {}
    private function actor(): StockUser {$actor=$this->getUser();if(!$actor instanceof StockUser) {throw new ApiProblem(401,'authentication_required','Connectez-vous.');}return $actor;}
    #[Route('/api/v1/consumptions',name:'api_consumptions_list',methods:['GET'])]
    public function api(Request $request,QueryParameters $query): JsonResponse {return $this->json($this->history->listing($this->actor(),$query->read($request,'listConsumptions')));}
    #[Route('/consommations',name:'consumptions_list',methods:['GET'])]
    public function index(Request $request,QueryParameters $query,StoreContext $context,CatalogDirectory $catalog): Response
    {
        $filtered=$request->duplicate();foreach($request->query->all() as $name=>$value) {if(in_array($name,['date_from','date_to','article_id','category_id','store_id','consumer_id'],true)&&$value==='') {$filtered->query->remove($name);}}
        if(!$filtered->query->has('order')) {$filtered->query->set('order','desc');}$filters=$query->read($filtered,'listConsumptions');$actor=$this->actor();
        if(!$request->query->has('store_id')&&($selected=$context->read($request,$actor)['selected'])!==null) {$filters['store_id']=$selected;}
        foreach(['article_id','category_id','store_id','consumer_id'] as $key) {if(isset($filters[$key])) {$filters[$key]=strtolower($filters[$key]);}}
        $result=$this->history->listing($actor,$filters);
        return $this->render('consumptions/list.html.twig',['result'=>$result,'filters'=>$filters,'options'=>$this->history->options($actor),'categories'=>$catalog->categoryOptions($actor)]);
    }
}
