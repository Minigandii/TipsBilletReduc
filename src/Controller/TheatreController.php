<?php

namespace App\Controller;

use App\Entity\Theatre;
use Stripe\Stripe;
use Stripe\Account;
use Stripe\AccountLink;
use App\Entity\Utilisateur;
use App\Repository\OuvreurRepository;
use App\Form\TheatreFormType;
use App\Form\EditTheatreFormType;
use App\Repository\TheatreRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;

class TheatreController extends AbstractController
{
    #[Route('/theatre', name: 'app_theatre')]
    public function index(OuvreurRepository $ouvreurRepository, TheatreRepository $theatreRepository): Response
    {
        $user = $this->getUser();

        if ($user instanceof Utilisateur) {
            $id = $user->getId();
            $theatre = $theatreRepository->getTheatreById($id);
        } 

        $ouvreurs = $ouvreurRepository->findByTheatreId($id);
        

        return $this->render('theatre/index.html.twig', [
            'ouvreurs' => $ouvreurs,
            'theatre' => $theatre,
            'controller_name' => 'TheatreController'
        ]);
    }
    
    #[Route('/theatre/viewtheatre/{id}', name: 'app_view_theatre')]
    public function view(Theatre $theatre, $id, OuvreurRepository $ouvreurRepository): Response
    {
        $ouvreurs = $ouvreurRepository->findByTheatreId($id);

        return $this->render('theatre/viewtheatre.html.twig', [
            'theatre' => $theatre,
            'ouvreurs' => $ouvreurs
        ]);
    }

    #[Route('/admin/viewtheatreadmin/{id}', name: 'app_view_theatre_admin')]
    public function viewadmintheatre(Theatre $theatre, $id, OuvreurRepository $ouvreurRepository): Response
    {
        $ouvreurs = $ouvreurRepository->findByTheatreId($id);

        return $this->render('admin/viewtheatreadmin.html.twig', [
            'theatre' => $theatre,
            'ouvreurs' => $ouvreurs
        ]);
    }

    #[Route('/admin/addTheatre', name: 'app_add_theatre')]
    public function addTheatre(KernelInterface $kernel,RouterInterface $router,$stripeSK,Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $userPasswordHasher): Response
    {

        $theatre = new Theatre();

        $httpClient = HttpClient::create();
        $responseData = $httpClient->request('POST', 'https://api.billetreduc.com/api/auth/login', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'userName' => 'epfprojects@billetreduc.fr', 
                'password' => 'LtlcHsFhWkOa7aZbDLOU',
            ]),
        ]);
        $tokenData = $responseData->toArray();
        $token = $tokenData['auth_token']; 

        $responseTheatres = $httpClient->request('GET', 'https://api.billetreduc.com/api/Export/theaters', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        $theatresData = $responseTheatres->toArray();

        $theatreOptions = [];
        foreach ($theatresData as $th) {
            foreach ($th as $t){
                $theatreOptions[$t['id']] = $t['name'] . '*' . $t['address'] . '*' .$t['postalCode'] . '*' .$t['city'].'*'.$t['id']; 
            }
        }

                // Triez le tableau $theatreOptions par ordre alphabétique en utilisant usort
        usort($theatreOptions, function ($a, $b) {
            // Séparez les éléments en utilisant l'étoile (*) et comparez les noms (indice 0)
            $nameA = explode('*', $a)[0];
            $nameB = explode('*', $b)[0];
            
            // Utilisez strcmp pour comparer les noms de manière insensible à la casse
            return strcmp(strtolower($nameA), strtolower($nameB));
        });

        
        $addTheatreform = $this->createForm(TheatreFormType::class, $theatre, [
            'theatres' => ($theatreOptions),
        ]);


       // $addTheatreform = $this->createForm(TheatreFormType::class, $theatre);

        $addTheatreform->handleRequest($request);
        if ($addTheatreform->isSubmitted() && $addTheatreform->isValid()) {

            $theatre->setRoles(['ROLE_MODERATOR']);
            $theatre->setPassword(
                $userPasswordHasher->hashPassword(
                    $theatre,
                    $addTheatreform->get('password')->getData()
                )
            );


            $theatreInfo = $addTheatreform->get('BRId')->getData();
            $theatreChaine = explode('*', $theatreInfo); //chaine divisée en éléments

            $theatreBRId =$theatreChaine[4]; //dernier element : id

            $theatreName = $theatreChaine[0]; //premier element : nom 

            $theatreAddress = $theatreChaine[1].', '.$theatreChaine[2].', '.$theatreChaine[3];
            
            $theatre->setNom($theatreName);
            $theatre->setAdresse($theatreAddress);
            $theatre->setBRId($theatreBRId);
            $theatre->setQrcode("");
            $theatre->setStripeAccountId('start'); //oblige de mettre un truc pour creer le theatre, et on lui cree son vrai juste apres

            $entityManager->persist($theatre);
            $entityManager->flush();

            $email = $theatre->getEmail();
            Stripe::setApiKey($stripeSK);
            $account = Account::create([
                'type' => 'standard', 
                'country' => 'FR', 
                'email' => $email, 
            ]);
            $theatre->setStripeAccountId($account->id);

            $theatreId = $theatre->getId();

            $url = $router->generate('payment', ['id' => $theatreId], UrlGeneratorInterface::ABSOLUTE_URL);

            // URL du service de génération de QR Code
            $url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=". urlencode($url);

            // Récupérez l'image du QR Code à partir de l'URL
            $qrCodeImage = file_get_contents($url);

            if ($qrCodeImage === false) {
                throw new \Exception('Erreur lors de la récupération de l\'image du QR Code');
            }

            $theatreName = $theatre->getNom(); // Assurez-vous d'ajuster cette ligne en fonction de votre entité Theatre

            // Remplacez les caractères spéciaux et espaces par des tirets (ou utilisez une autre logique de nettoyage si nécessaire)
            $cleanedTheatreName = preg_replace('/[^a-zA-Z0-9]+/', '-', $theatreName);

            // Créez le nom du fichier en ajoutant l'extension '.png'
            $filename = $cleanedTheatreName . '.png';

            // Obtenez le chemin du répertoire racine de votre projet
            $projectDir = $kernel->getProjectDir();

            // Définissez le chemin relatif vers le répertoire QrCode
            $destinationPath = $projectDir . '/public/QrCode/';

            // Enregistrez l'image dans le dossier de destination
            file_put_contents($destinationPath . $filename, $qrCodeImage);

            $theatre->setQrcode($filename);
            
            $entityManager->persist($theatre);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin');
        }

        return $this->render('admin/addTheatre.html.twig', [
            'addTheatreform' => $addTheatreform->createView()
        ]);
    }

    #[Route('create-stripe-account-link', name: 'app_create_account_stripe_link')]
    public function createAccountLink($stripeSK): Response
    {
        Stripe::setApiKey($stripeSK);

        $stripeAccountId = $this->getUser()->getStripeAccountId();

        $accountLink = AccountLink::create([
            'account' => $stripeAccountId,
            'refresh_url' => $this->generateUrl('app_theatre', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'return_url' => $this->generateUrl('app_theatre', [], UrlGeneratorInterface::ABSOLUTE_URL),            
            'type' => 'account_onboarding',
        ]);

        return $this->redirect($accountLink->url);
    }

    #[Route('/theatre/editTheatre/{id}', name: 'app_edit_theatre')]
    public function EditTheatre(Request $request, EntityManagerInterface $entityManager,  Theatre $theatre): Response
    {

        $editTheatreForm = $this->createForm(EditTheatreFormType::class, $theatre);

        $editTheatreForm->handleRequest($request);
        if ($editTheatreForm->isSubmitted() && $editTheatreForm->isValid()) {

            $entityManager->persist($theatre);
            $entityManager->flush();

            return $this->redirectToRoute('app_theatre');
        }

        return $this->render('theatre/editTheatre.html.twig', [
            'editTheatreForm' => $editTheatreForm->createView(),
            'theatre' => $theatre,
        ]);
    }

    #[Route('/admin/editTheatre/{id}', name: 'app_admin_edit_theatre')]
    public function AdminEditTheatre(Request $request, EntityManagerInterface $entityManager,  Theatre $theatre): Response
    {

        $editTheatreForm = $this->createForm(EditTheatreFormType::class, $theatre);

        $editTheatreForm->handleRequest($request);
        if ($editTheatreForm->isSubmitted() && $editTheatreForm->isValid()) {

            $entityManager->persist($theatre);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin');
        }

        return $this->render('theatre/editTheatre.html.twig', [
            'editTheatreForm' => $editTheatreForm->createView(),
            'theatre' => $theatre,
        ]);
    }


    #[Route('/admin/deleteTheatre/{id}', name: 'app_delete_theatre')]
    public function deleteTheatre($id, EntityManagerInterface $entityManager): Response
    {
        $theatreRepository = $entityManager->getRepository(Theatre::class);
        $theatreToDelete = $theatreRepository->find($id);

        if (!$theatreToDelete) {
            // Gérer le cas où le théâtre n'est pas trouvé, par exemple, rediriger avec un message d'erreur.
        }

        $entityManager->remove($theatreToDelete);
        $entityManager->flush();

        // Rediriger vers une page, peut-être la liste des théâtres
        return $this->redirectToRoute('app_admin');
    }


    #[Route("/admin/confirm_delete_theatre/{id}", name: 'app_confirm_delete_theatre')]

    public function confirmDeleteTheatre($id, EntityManagerInterface $entityManager, Request $request): Response
    {
        $theatreRepository = $entityManager->getRepository(Theatre::class);
        $theatreToDelete = $theatreRepository->find($id);

        if (!$theatreToDelete) {
            return $this->redirectToRoute('app_admin');
        }

        return $this->render('admin/confirm_delete_theatre.html.twig', [
            'theatre' => $theatreToDelete,
        ]);
    }

    #[Route('/theatre/viewQr', name: 'app_theatre_view_qr')]
    public function viewQr(TheatreRepository $theatreRepository): Response
    {
        $user = $this->getUser();

        if ($user instanceof Utilisateur) {
            $id = $user->getId();
            $theatre = $theatreRepository->getTheatreById($id);
        } 

        return $this->render('theatre/viewQr.html.twig', [
            'theatre' => $theatre,
        ]);
    }

    #[Route('/admin/viewQr/{id}', name: 'app_admin_view_qr')]
    public function viewQrAdmin(TheatreRepository $theatreRepository, $id): Response
    {

        $theatre = $theatreRepository->getTheatreById($id);

        return $this->render('theatre/viewQr.html.twig', [
            'theatre' => $theatre,
        ]);
    }

}
