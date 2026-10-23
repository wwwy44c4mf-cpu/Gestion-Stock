<?php

declare(strict_types=1);
namespace App\Controller;
use App\Access\CatalogDirectory;
use App\Api\{ApiProblem,QueryParameters};
use App\Consumption\ConsumptionHistory;
use App\Security\StockUser;
use App\Statistics\ConsumptionStatistics;
use App\Ui\StoreContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse,Request,Response};
use Symfony\Component\Routing\Attribute\Route;
final class ConsumptionStatisticsController extends AbstractController
{
    public function __construct(private readonly ConsumptionStatistics $statistics) {}
    private function actor(): StockUser {$actor=$this->getUser();if(!$actor instanceof StockUser) {throw new ApiProblem(401,'authentication_required','Connectez-vous.');}return $actor;}
    #[Route('/api/v1/statistics/consumptions',name:'api_statistics_consumptions',methods:['GET'])]
    public function api(Request $request,QueryParameters $query): JsonResponse {$result=$this->statistics->read($this->actor(),$query->read($request,'consumptionStatistics'));return $this->json(['data'=>$result['data']]);}
    #[Route('/statistiques',name:'statistics_consumptions',methods:['GET'])]
    public function index(Request $request,QueryParameters $query,ConsumptionHistory $history,CatalogDirectory $catalog,StoreContext $context, \Doctrine\DBAL\Connection $db): Response
    {
        $actor=$this->actor();$filtered=$request->duplicate();foreach($request->query->all() as $key=>$value) {if(in_array($key,['date_from','date_to','store_id','category_id'],true)&&$value==='') {$filtered->query->remove($key);}}
        if(!$request->query->has('date_from')&&!$request->query->has('date_to')) {$today=new \DateTimeImmutable('now',new \DateTimeZone($actor->timezone));$filtered->query->set('date_from',$today->format('Y-m-01'));$filtered->query->set('date_to',$today->format('Y-m-d'));}
        $filters=$query->read($filtered,'consumptionStatistics');foreach(['store_id','category_id'] as $key) {if(isset($filters[$key])) {$filters[$key]=strtolower($filters[$key]);}}
        if(!$request->query->has('store_id')&&($selected=$context->read($request,$actor)['selected'])!==null) {$filters['store_id']=$selected;}
        $stores=$history->options($actor)['stores'];
        if($actor->role==='USER') {
            $deposits=$db->fetchAllAssociative("SELECT DISTINCT s.id,s.code,s.name,s.active FROM stores s JOIN stock_movements r ON r.destination_store_id=s.id JOIN stock_movements m ON m.id=r.related_movement_id WHERE r.type='return' AND m.consumer_id=? AND s.active ORDER BY s.name,s.id",[$actor->id]);
            $stores=array_values(array_column([...$stores,...$deposits],null,'id'));
            usort($stores,fn($a,$b)=>[$a['name'],$a['id']]<=>[$b['name'],$b['id']]);
        }
        return $this->render('statistics/consumptions.html.twig',['result'=>$this->statistics->read($actor,$filters),'filters'=>$filters,'stores'=>$stores,'categories'=>$catalog->categoryOptions($actor)]);
    }
}
