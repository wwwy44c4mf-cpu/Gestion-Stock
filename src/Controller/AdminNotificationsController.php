<?php

declare(strict_types=1);
namespace App\Controller;
use App\Administration\AdminInbox;
use App\Security\StockUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
#[IsGranted('APP_ACCOUNTS_ADMIN')]
final class AdminNotificationsController extends AbstractController
{
    #[Route('/administration/notifications',name:'admin_users_notifications',methods:['GET'])]
    public function index(AdminInbox $inbox): Response
    {
        $actor=$this->getUser();if(!$actor instanceof StockUser) {throw $this->createAccessDeniedException();}
        return $this->render('admin/notifications.html.twig',['notifications'=>$inbox->items($actor)]);
    }
}
