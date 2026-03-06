<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use DateTime;
use DateInterval;

class DashboardController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route(path: '/', name: 'dashboard')]
    public function index(): Response
    {
        // Community Statistiken
        $totalUsersCount = $this->em->getRepository('App:User')->count([]);
        
        // Neue User diese Woche
        $weekAgo = new DateTime('now');
        $weekAgo->sub(new DateInterval('P7D'));
        $newUsersQuery = $this->em->createQuery(
            'SELECT COUNT(u.id) FROM App:User u WHERE u.createdAt >= :weekAgo'
        )->setParameter('weekAgo', $weekAgo);
        $newUsersCount = (int)$newUsersQuery->getSingleScalarResult();
        
        // Clans
        $clansRepo = $this->em->getRepository('App:Clan');
        $totalClans = $clansRepo->count([]);
        $openClans = $clansRepo->count(['joinAccept' => true]);
        
        // Tickets Statistiken
        $ticketRepo = $this->em->getRepository('App:Ticket');
        $totalTickets = $ticketRepo->count([]);
        
        // Tickets verkauft (über ShopOrderPosition)
        $ticketsSoldQuery = $this->em->createQuery(
            'SELECT COUNT(pos.id) FROM App:ShopOrderPosition pos WHERE pos.ticket IS NOT NULL'
        );
        $ticketsSoldCount = (int)$ticketsSoldQuery->getSingleScalarResult();
        
        $ticketsAvailable = $totalTickets - $ticketsSoldCount;
        $ticketPercentage = $totalTickets > 0 ? round(($ticketsSoldCount / $totalTickets) * 100) : 0;
        
        // Ausstehende Bestellungen (nicht abgeschlossen)
        $pendingOrdersQuery = $this->em->createQuery(
            'SELECT COUNT(o.id) FROM App:ShopOrder o WHERE o.statusId != 
             (SELECT s.id FROM App:ShopOrderStatus s WHERE s.name = \'Completed\')'
        );
        $pendingOrdersCount = (int)$pendingOrdersQuery->getSingleScalarResult();
        
        // Umsatz diese Woche
        $revenueWeekQuery = $this->em->createQuery(
            'SELECT SUM(o.totalPrice) FROM App:ShopOrder o WHERE o.createdAt >= :weekAgo'
        )->setParameter('weekAgo', $weekAgo);
        $revenueWeek = (float)($revenueWeekQuery->getSingleScalarResult() ?? 0);
        
        // Umsatz diesen Monat
        $monthAgo = (new DateTime())->modify('first day of this month');
        $revenueMonthQuery = $this->em->createQuery(
            'SELECT SUM(o.totalPrice) FROM App:ShopOrder o WHERE o.createdAt >= :monthAgo'
        )->setParameter('monthAgo', $monthAgo);
        $revenueMonth = (float)($revenueMonthQuery->getSingleScalarResult() ?? 0);
        
        // Top-Artikel (meistverkaufte Addons)
        $topItemsQuery = $this->em->createQuery(
            'SELECT sa.name, COUNT(posa.id) as cnt FROM App:ShopOrderPositionAddon posa 
             JOIN posa.addon sa GROUP BY sa.id ORDER BY cnt DESC'
        )->setMaxResults(5);
        $topItems = $topItemsQuery->getResult();

        return $this->render('admin/dashboard/index.html.twig', [
            'communityStats' => [
                'totalUsers' => $totalUsersCount,
                'newUsersThisWeek' => $newUsersCount,
                'clansTotal' => $totalClans,
                'clansOpen' => $openClans,
            ],
            'shopStats' => [
                'ticketsTotal' => $totalTickets,
                'ticketsSold' => $ticketsSoldCount,
                'ticketsAvailable' => $ticketsAvailable,
                'ticketPercentage' => $ticketPercentage,
                'pendingOrders' => $pendingOrdersCount,
                'revenueWeek' => $revenueWeek,
                'revenueMonth' => $revenueMonth,
                'topItems' => $topItems,
            ],
        ]);
    }
}
