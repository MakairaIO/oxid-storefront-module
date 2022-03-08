<?php

namespace Marmalade\OxidApi\Controller;


use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\Country;
use OxidEsales\Eshop\Application\Model\Rating;
use OxidEsales\Eshop\Application\Model\Review;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Exception\CookieException;
use OxidEsales\Eshop\Core\Exception\NoArticleException;
use OxidEsales\Eshop\Core\Exception\UserException;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class OxidApi extends FrontendController
{
    public function render()
    {
        $this->sendResponse('', 400);
    }

    public function getCart()
    {
        $session = Registry::getSession();
        $basket = $session->getBasket();

        $response = [
            'itemCount' => $basket->getItemsCount(),
            'totalSum' => (float) $basket->getBruttoSum()
        ];

        $this->sendResponse($response);
    }

    public function addToCart()
    {
        ['id' => $productId, 'amount' => $amount] = $this->getRequestBody();

        $session = Registry::getSession();
        $basket = $session->getBasket();

        try {
            $basketItem = $basket->addToBasket($productId, $amount, null, null, false);
            $basket->calculateBasket();

            $response = [
                'success' => true,
                'itemCount' => $basket->getItemsCount(),
                'totalSum' => $basket->getBruttoSum()
            ];
        } catch (NoArticleException $e) {
            $response = [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        $this->sendResponse($response);
    }

    public function getUser()
    {
        $response = [];
        $user = Registry::getSession()->getUser();
        if ($user) {
            $response = [
                'success' => true,
                'firstname' => $user->getFieldData('oxfname'),
                'lastname' => $user->getFieldData('oxlname'),
                'email' => $user->getFieldData('oxusername'),
            ];
        }

        $this->sendResponse($response);
    }

    public function login()
    {
        ['username' => $username, 'password' => $password, 'rememberLogin' => $rememberLogin] = $this->getRequestBody();

        try {
            /** @var User $user */
            $user = oxNew(User::class);
            $user->login($username, $password, $rememberLogin);
            $response = [
                'success' => true
            ];
        } catch (UserException $userException) {
            $response = [
                'success' => false,
                'message' => $userException->getMessage()
            ];
        } catch (CookieException $cookieException) {
            $response = [
                'success' => false,
                'message' => $cookieException->getMessage()
            ];
        }

        // after login
        $session = Registry::getSession();
        if ($session->isSessionStarted()) {
            $session->regenerateSessionId();
        }

        // this user is blocked, deny him
        if ($user->inGroup('oxidblocked')) {
            $response = [
                'success' => false,
                'message' => 'USER_BLOCKED'
            ];
        }

        // recalc basket
        if ($basket = $session->getBasket()) {
            $basket->onUpdate();
        }
        $this->sendResponse($response);
    }

    public function logout()
    {
        $user = oxNew(User::class);
        $user->logout();

        // after logout
        Registry::getSession()->deleteVariable('paymentid');
        Registry::getSession()->deleteVariable('sShipSet');
        Registry::getSession()->deleteVariable('deladrid');
        Registry::getSession()->deleteVariable('dynvalue');

        // resetting & recalc basket
        if (($basket = Registry::getSession()->getBasket())) {
            $basket->resetUserInfo();
            $basket->onUpdate();
        }

        Registry::getSession()->delBasket();

        $this->sendResponse(["success" => true]);
    }

    public function getReviews()
    {
        ['id' => $productId, 'limit' => $limit, 'offset' => $offset] = $this->getRequestBody();

        $product = oxNew(Article::class);
        $product->load($productId);

        $reviews = $product->getReviews();
        $reviewsArray =  $reviews ? $reviews->getArray() : [];
        if ((int) $limit > 0) {
            $reviews = array_slice($reviewsArray, $offset ?? 0, (int) $limit);
        }

        $response = [];

        /** @var Review $review */
        foreach ($reviewsArray as $review) {
            $user = oxNew(User::class);
            $user->load($review->getFieldData('oxuserid'));
            $response[] = [
                'name' => $user->getFieldData('oxfname'),
                'rating' => $review->getFieldData('oxrating'),
                'text' => $review->getFieldData('oxtext'),
                'created' => $review->getFieldData('oxcreate'),
            ];
        }

        $this->sendResponse($response);
    }

    public function createReview()
    {
        ['id' => $productId, 'rating' => $rating, 'text' => $text] = $this->getRequestBody();

        $product = oxNew(Article::class);
        $product->load($productId);
        // TODO check if product loaded

        $user = Registry::getSession()->getUser();
        // TODO check if user is logged in
        if ($rating !== null && $rating >= 1 && $rating <= 5) {
            $ratingModel = oxNew(Rating::class);
            if ($ratingModel->allowRating($user->getId(), 'oxarticle', $product->getId())) {
                $ratingModel->oxratings__oxuserid = new Field($user->getId());
                $ratingModel->oxratings__oxtype = new Field('oxarticle');
                $ratingModel->oxratings__oxobjectid = new Field($product->getId());
                $ratingModel->oxratings__oxrating = new Field($rating);
                $ratingModel->save();
                $product->addToRatingAverage($rating);
            }
        }

        if ($reviewText = trim($text)) {
            $review = oxNew(Review::class);
            $review->oxreviews__oxobjectid = new Field($product->getId());
            $review->oxreviews__oxtype = new Field('oxarticle');
            $review->oxreviews__oxtext = new Field($reviewText, Field::T_RAW);
            $review->oxreviews__oxlang = new Field(Registry::getLang()->getBaseLanguage());
            $review->oxreviews__oxuserid = new Field($user->getId());
            $review->oxreviews__oxrating = new Field(($rating !== null) ? $rating : 0);
            $review->save();
        }

        $this->sendResponse(['success' => 'true']);
    }

    public function addToWishlist()
    {
        ['id' => $productId] = $this->getRequestBody();

        if (!$this->getViewConfig()->getShowWishlist()) {
            $this->sendResponse([
                'success' => false,
                'message' => 'ERROR_WISHLIST_NOT_AVAILABLE',
            ]);
        }

        if (!$user = Registry::getSession()->getUser()) {
            $this->sendResponse([
                'success' => false,
                'message' => 'ERROR_NO_USER',
            ]);
        }

        $basket = $user->getBasket('noticelist');
        $amount = $basket->addItemToBasket($productId, 1, null, true);

        if ($amount > 0) {
            $this->sendResponse([
                'success' => true,
                'itemCount' => $basket->getItemCount(true)
            ]);
        }

        $this->sendResponse([
            'success' => false,
            'message' => 'ERROR_PRODUCT_NOT_ADDED'
        ]);
    }

    public function subscribeNewsletter()
    {
        ['email' => $email] = $this->getRequestBody();

        try {
            /** @var Container $container */
            $container         = ContainerFactory::getInstance()->getContainer();
            $oxidConfig        = Registry::getConfig();
            $groupId           = $oxidConfig->getShopConfVar('default_group_id', null, 'module:marm/mapp-newsletter');
            $newsletterService = $container->get('mapp_newsletter');
            $response          = ['success' => true];
            try {
                // Let's check if an user exists.
                $newsletterService->getUser($email);
            } catch (\SoapFault $exception) {
                // An exception is thrown if the user doesn't exists.
                // Don't handle this exception if it's not "not found" error.
                if (!isset($exception->detail->NoSuchObjectException)) {
                    throw $exception;
                }

                // Throw the fault again if it's not an "user not found" error.
                $errorInfo = $exception->detail->NoSuchObjectException;
                if ('NO_SUCH_OBJECT' !== $errorInfo->errorCode || 'User' !== $errorInfo->objectType) {
                    throw $exception;
                }

                $user = ['email' => $email];

                // The user doesn't exist, create it.
                $newsletterService->createUser($user);
            }

            $newsletterService->subscribe($email, $groupId);
        } catch (\SoapFault $exception) {
            $container->get('monolog.logger.mapp')->error($exception->getMessage(), $exception->getTrace());
            $response = [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }

        $this->sendResponse($response);
    }

    private function getRequestBody()
    {
        $request = Request::createFromGlobals();
        $body = $request->getContent();

        return json_decode($body, true);
    }

    private function sendResponse($content, $status = 200)
    {
        Registry::getSession()->freeze();
        (new JsonResponse($content, $status))->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_PRESERVE_ZERO_FRACTION)->setData($content)->send();
        exit(0);
    }
}
