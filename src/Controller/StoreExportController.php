<?php

declare(strict_types=1);
namespace App\Controller;
use App\Api\ApiProblem;
use App\Export\{StoreStockSnapshot,StockWorkbook};
use App\Security\StockUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,ResponseHeaderBag};
use Symfony\Component\Routing\Attribute\Route;
final class StoreExportController extends AbstractController
{
    #[Route('/magasins/{id}/export.xlsx',name:'store_export',methods:['GET'])]
    #[Route('/api/v1/stores/{id}/export.xlsx',name:'api_store_export',methods:['GET'])]
    public function export(string $id,Request $request,StoreStockSnapshot $snapshots,StockWorkbook $workbooks): Response
    {
        $actor=$this->getUser();if(!$actor instanceof StockUser) {throw new ApiProblem(401,'authentication_required','Connectez-vous.');}
        try {
        if($request->query->count()) {throw new ApiProblem(422,'invalid_query','Cet export concerne l’ensemble du magasin, sans filtre.');}
        $snapshot=$snapshots->read($actor,$id);
        } catch(ApiProblem $e) {
            if(str_starts_with($request->getPathInfo(),'/api/')) {throw $e;}
            throw new \Symfony\Component\HttpKernel\Exception\HttpException($e->status,$e->getMessage(),$e);
        }
        $bytes=$workbooks->create($snapshot);
        $filename='stock-'.(preg_replace('/[^a-zA-Z0-9_-]/','-',$snapshot['store']['code'])?:'magasin').'-'.(new \DateTimeImmutable($snapshot['captured_at']))->format('Ymd-His').'.xlsx';
        $response=new Response($bytes,200,['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','Cache-Control'=>'private, no-store, max-age=0','X-Content-Type-Options'=>'nosniff']);
        $response->headers->set('Content-Disposition',$response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT,$filename));
        return $response;
    }
}
