<?php

namespace App\Controller;

use Doctrine\Common\Cache\Psr6\CacheItem;
use Psr\Cache\CacheItemPoolInterface;
use SpotifyWebAPI\Session;
use SpotifyWebAPI\SpotifyWebAPI;
use SpotifyWebAPI\SpotifyWebAPIAuthException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SpotifyController extends AbstractController
{
    public function __construct(
        private readonly SpotifyWebAPI $api,
        private readonly Session $session,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    #[Route('/', name: 'app_spotify_update_my_playlist')]
    public function updateMyPlayList(): Response
    {
        if (!$this->cache->hasItem('spotify_access_token')) {
            return $this->redirectToRoute('app_spotify_redirect');
        }

        $this->api->setAccessToken($this->cache->getItem('spotify_access_token')->get());

        $top50 = $this->api->getMyTop('tracks', [
            'limit' => 50,
            'time_range' => 'short_term',
        ]);

        $top50TracksIds = array_map(fn($track) => $track->id, $top50->items);

        $playlistID = $this-> getParameter('SPOTIFY_PLAYLIST_ID');

        $this->api->replacePlaylistTracks($playlistID, $top50TracksIds);

        return $this->render('spotify/index.html.twig', [
            'tracks' => $this->api->getPlaylistTracks($playlistID),
        ]);
    }

    #[Route('/callback', name: 'app_spotify')]
    Public function callbackFromSpotify(Request $request): Response
    {
        try {
            $this->session->requestAccessToken($request->query->get('code'));
        } catch (SpotifyWebAPIAuthException $e) {
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }


        $cacheIteme = $this->cache->getItem('spotify_access_token');
        $cacheIteme->set($this->session->getAccessToken());
        $cacheIteme->expiresAfter(3600);
        $this->cache->save($cacheIteme);
        
        return $this->redirectToRoute('app_spotify_update_my_playlist');
    }

    #[Route('/redirect', name: 'app_spotify_redirect')]
    public function redirectToSpotify(): Response
    {
        $options = [
            'scope' => [
                'user-read-email',
                'playlist-read-private',
                'playlist-modify-private',
                'playlist-modify-public',
                'user-top-read',
            ],
        ];

        return $this->redirect($this->session->getAuthorizeUrl($options));
    }
}