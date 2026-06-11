<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FeedController extends AbstractController
{
    #[Route('/', name: 'app_index')]
    public function index(): Response
    {
        $feedPath = $this->getParameter('kernel.project_dir') . '/public/combined_feed.atom';

        if (file_exists($feedPath)) {
            return $this->redirect('/combined_feed.atom');
        }

        return new Response(
            '<html><body>Feed is being processed. Please check back in a few minutes.</body></html>',
            Response::HTTP_OK
        );
    }
}
