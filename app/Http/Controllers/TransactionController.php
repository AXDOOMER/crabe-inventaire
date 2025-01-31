<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;
use PayPal\Api\Amount;
use PayPal\Api\Item;

/** All Paypal Details class **/
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;
use Redirect;
use Session;
use URL;
use App\AppTransaction;
use App\Log;
use Illuminate\Support\Facades\Auth;
use App\Product;

class TransactionController extends Controller
{
    private $_api_context;
    private $log;
    private $appTransaction;
    private const CURRENCY = "CAD";
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {

        /** PayPal api context **/
        $paypal_conf = \Config::get('paypal');
        $this->_api_context = new ApiContext(new OAuthTokenCredential(
            $paypal_conf['client_id'],
            $paypal_conf['secret'])
        );
        $this->_api_context->setConfig($paypal_conf['settings']);

    }
    public function index()
    {
        return view('paypal/paywithpaypal');
    }
    public function transactionPayement(Request $request)
    {
        $listOfProducts = array();
        $total = 0.00;
        $userIdData['user_id'] = Auth::id();
        $this->log = new Log($userIdData);
        $this->appTransaction = new AppTransaction($userIdData);
          
        $validatedData = $request->validate([
            'products' => 'array|required',
            'description' => 'string|required',
        ]);
        
        $products = $validatedData["products"];
        $description = $validatedData["description"];

        foreach ($products as &$productValue) {

            $product = Product::findOrFail($productValue['id']);

            if((int)$product->quantity - (int)$productValue['quantity'] > 0){
                $total += (float)$product->price*(int)$productValue['quantity'];

                $payer = new Payer();
                $payer->setPaymentMethod('paypal');
    
                $item_1 = new Item();
    
                $item_1->setName($product->name) 
                    ->setCurrency(self::CURRENCY)
                    ->setQuantity($productValue['quantity'])
                    ->setPrice($product->price); 
    
                array_push($listOfProducts,$item_1);
            }else{
                return Redirect::to('/home')->with('error', 'the quantity of this pruduct '+$product->name+' is not availble,' +$product->quantity+"  are left");
            }
           
        }

        $item_list = new ItemList();
        $item_list->setItems($listOfProducts);

        $amount = new Amount();
        $amount->setCurrency(self::CURRENCY)
            ->setTotal($total);

        $transaction = new Transaction();
        $transaction->setAmount($amount)
            ->setItemList($item_list)
            ->setDescription($description);

        // TODO put the right redirect url for each transaction type
        $redirect_urls = new RedirectUrls();
        $redirect_urls->setReturnUrl(URL::to('statustransaction')) 
            ->setCancelUrl(URL::to('home'));

        $payment = new Payment();
        $payment->setIntent('Sale')
            ->setPayer($payer)
            ->setRedirectUrls($redirect_urls)
            ->setTransactions(array($transaction));
      
        

        try {

            $payment->create($this->_api_context);
           
        } catch (\PayPal\Exception\PPConnectionException $ex) {

            if (\Config::get('app.debug')) {
                \Session::put('error', 'Connection timeout');
                $this->log->status = 'failed';
                $this->log->message = $payment->getFailureReason();
                $this->log->paymentId = $payment->getId();
                $this->log->token = $payment->getToken();
                $this->log->code=  $ex->getCode();
                $this->log->save();

                $this->appTransaction->status = 'failed';
                $this->appTransaction->save();
                // TODO put the right redirect url for each transaction type
                return Redirect::to('/home')->with('error', 'Unknown error occurred  while processing the transaction');

            } else {

                \Session::put('error', 'Some error occur, sorry for inconvenient');
                $this->log->status = 'failed';
                $this->log->message = $payment->getFailureReason() ;;
                $this->log->paymentId = $payment->getId();
                $this->log->token = $payment->getToken();
                $this->log->code=  $ex->getCode();
                $this->log->save();

                $this->appTransaction->status = 'failed';
                $this->appTransaction->save();
                // TODO put the right redirect url for each transaction type
                return Redirect::to('/home')->with('error', 'Unknown error occurred  while processing the transaction');;

            }

        }
  
        foreach ($payment->getLinks() as $link) {

            if ($link->getRel() == 'approval_url') {

                $redirect_url = $link->getHref();
                break;

            }

        }

        /** add payment ID to session **/
        \Session::put('paypal_payment_id', $payment->getId());

        
        if (isset($redirect_url)) {
            // save the transaction to database
            $this->appTransaction->paymentId = $payment->getId();
            $this->appTransaction->token = $payment->getToken();
            $this->appTransaction->total = $total;
            $this->appTransaction->products = $products;
            $this->appTransaction->status = 'waiting on client authorization';
            $this->appTransaction->save();

            $this->log->status = $payment->getState();
                $this->log->message = (empty($payment->getFailureReason())? "redirecting to paypakl succeded":$payment->getFailureReason());
                $this->log->paymentId = $payment->getId();
                $this->log->token = $payment->getToken();
                $this->log->save();
            /** redirect to paypal **/
            return Redirect::away($redirect_url)->with('error', $payment->getId());

        }

        \Session::put('error', 'Unknown error occurred');
        return Redirect::to('/home')->with('error', 'Unknown error occurred  while processing the transaction');

    }
    public function payAccountActivation(Request $request)
    {
        $listOfProducts = array();
        $total = 0.00;
        $userIdData['user_id'] = Auth::id();
        $this->log = new Log($userIdData);
        $this->appTransaction = new AppTransaction($userIdData);
          
        $validatedData = $request->validate([
            'products' => 'array|required',
            'description' => 'string|required',
        ]);
        
        $products = $validatedData["products"];
        $description = $validatedData["description"];

        foreach ($products as &$productValue) {

            $product = Product::find($productValue['id']);
            $total += (float)$product->price*(int)$productValue['quantity'];

            $payer = new Payer();
            $payer->setPaymentMethod('paypal');

            $item_1 = new Item();

            $item_1->setName($product->name) 
                ->setCurrency(self::CURRENCY)
                ->setQuantity($productValue['quantity'])
                ->setPrice($product->price); 

            array_push($listOfProducts,$item_1);
        }

        $item_list = new ItemList();
        $item_list->setItems($listOfProducts);

        $amount = new Amount();
        $amount->setCurrency(self::CURRENCY)
            ->setTotal($total);

        $transaction = new Transaction();
        $transaction->setAmount($amount)
            ->setItemList($item_list)
            ->setDescription($description);

        // TODO put the right redirect url for each transaction type
        $redirect_urls = new RedirectUrls();
        $redirect_urls->setReturnUrl(URL::to('statusactivation')) 
            ->setCancelUrl(URL::to('activer'));

        $payment = new Payment();
        $payment->setIntent('Sale')
            ->setPayer($payer)
            ->setRedirectUrls($redirect_urls)
            ->setTransactions(array($transaction));
      
        

        try {

            $payment->create($this->_api_context);
           
        } catch (\PayPal\Exception\PPConnectionException $ex) {

            if (\Config::get('app.debug')) {
                \Session::put('error', 'Connection timeout');
                $this->log->status = 'failed';
                $this->log->message = $payment->getFailureReason();
                $this->log->paymentId = $payment->getId();
                $this->log->token = $payment->getToken();
                $this->log->code=  $ex->getCode();
                $this->log->save();

                $this->appTransaction->status = 'failed';
                $this->appTransaction->save();
                // TODO put the right redirect url for each transaction type
                return Redirect::to('/home')->with('error', 'Unknown error occurred  while processing the transaction');;

            } else {

                \Session::put('error', 'Some error occur, sorry for inconvenient');
                $this->log->status = 'failed';
                $this->log->message = $payment->getFailureReason() ;;
                $this->log->paymentId = $payment->getId();
                $this->log->token = $payment->getToken();
                $this->log->code=  $ex->getCode();
                $this->log->save();

                $this->appTransaction->status = 'failed';
                $this->appTransaction->save();
                // TODO put the right redirect url for each transaction type
                return Redirect::to('/home')->with('error', 'Unknown error occurred  while processing the transaction');;

            }

        }
  
        foreach ($payment->getLinks() as $link) {

            if ($link->getRel() == 'approval_url') {

                $redirect_url = $link->getHref();
                break;

            }

        }

        /** add payment ID to session **/
        \Session::put('paypal_payment_id', $payment->getId());

        
        if (isset($redirect_url)) {
            // save the transaction to database
            $this->appTransaction->paymentId = $payment->getId();
            $this->appTransaction->token = $payment->getToken();
            $this->appTransaction->total = $total;
            $this->appTransaction->products = $products;
            $this->appTransaction->status = 'waiting on client authorization';
            $this->appTransaction->save();

            $this->log->status = $payment->getState();
                $this->log->message = (empty($payment->getFailureReason())? "redirecting to paypakl succeded":$payment->getFailureReason());
                $this->log->paymentId = $payment->getId();
                $this->log->token = $payment->getToken();
                $this->log->save();
            /** redirect to paypal **/
            return Redirect::away($redirect_url)->with('error', $payment->getId());

        }

        \Session::put('error', 'Unknown error occurred');
        return Redirect::to('/home')->with('error', 'Unknown error occurred  while processing the transaction');

    }

    /**
     * process payment trasaction after client authorization
    */
    public function getTransactionStatus(){

        $userIdData['user_id'] = Auth::id();

        /** Get the payment ID and clear it from session **/
        $payment_id = Session::get('paypal_payment_id');
        Session::forget('paypal_payment_id');
        //process errors
        if (empty(Input::get('PayerID')) || empty(Input::get('token'))) {
            $this->processErrorPayment($userIdData,'home', 'transaction failed or canceled , please try again');
            return Redirect::to('home')->with('error', 'transaction failed or canceled , please try again');
        }

        /**Execute the payment **/
        $payment = Payment::get($payment_id, $this->_api_context);
        $execution = new PaymentExecution();
        $execution->setPayerId(Input::get('PayerID'));
        $result = $payment->execute($execution, $this->_api_context);

        if ($result->getState() == 'approved') {

            $this->updateProductQuantity($userIdData,$result);
            return Redirect::to('home')->with('message', 'transaction succeded a receipt will be sent to you by email');

        }

        $this->processErrorPayment($userIdData,'activer', 'Unknown error occurred  while processing the transaction');
        return Redirect::to('home')->with('error', 'Unknown error occurred  while processing the transaction');


    }
    /**
     * process the account activation payment 
     */
    public function getPaymentStatusActivation()
    {
        $userIdData['user_id'] = Auth::id();

        /** Get the payment ID and clear it from session **/
        $payment_id = Session::get('paypal_payment_id');
        Session::forget('paypal_payment_id');
        //process errors
        if (empty(Input::get('PayerID')) || empty(Input::get('token'))) {
            $this->processErrorPayment($userIdData,'activer', 'transaction failed or canceled , please try again');
            return Redirect::to('activer')->with('error', 'transaction failed or canceled , please try again');
        }

        /**Execute the payment **/
        $payment = Payment::get($payment_id, $this->_api_context);
        $execution = new PaymentExecution();
        $execution->setPayerId(Input::get('PayerID'));
        $result = $payment->execute($execution, $this->_api_context);

        if ($result->getState() == 'approved') {

            $this->activateSubscription($userIdData,$result);
            return Redirect::to('home')->with('message', 'transaction succeded a receipt will be sent to you by email');

        }

        $this->processErrorPayment($userIdData,'activer', 'Unknown error occurred  while processing the transaction');
        return Redirect::to('activer')->with('error', 'Unknown error occurred  while processing the transaction');

    }

    /**
     * update the quantity of products availble after client transaction
    */
    public function updateProductQuantity($currentUserId,$result){
        $subscriptionAmount = $result->getTransactions()[0]->getAmount()->getTotal();
        $log = new Log($currentUserId);

        \Session::put('success', 'Payment success');
        $log->status = $result->getId();
        $log->message = 'transaction suscceded';
        $log->paymentId = $result->getId();
        $log->token = Input::get('token');
        $log->total = $subscriptionAmount;
        $log->save();

        $appTransaction = AppTransaction::where('token',Input::get('token'))->firstOrFail();

        foreach ($appTransaction->products as &$productValue) {

            $product = Product::findOrFail($productValue['id']);
            if((int)$product->quantity - (int)$productValue['quantity'] > 0){
              $product->updateQuantity($productValue['quantity']);
            }else{
                $appTransaction->status = 'failed, quantity of product not availble';
                $appTransaction->save();
                return Redirect::to('/home')->with('error', 'the quantity of this pruduct '+$product->name+' is not availble,' +$product->quantity+"  are left, please contact a crabe member for assistance");
            }
        }
        $appTransaction->status = 'success';
        $appTransaction->save();

    }
    /**
     * handle activation payement success
     */
    public function activateSubscription($currentUserId,$result){

        $user = Auth::user();
        $log = new Log($currentUserId);
        $subscriptionAmount = $result->getTransactions()[0]->getAmount()->getTotal();
        $log = new Log($currentUserId);

        \Session::put('success', 'Payment success');
        $log->status = $result->getId();
        $log->message = 'transaction suscceded';
        $log->paymentId = $result->getId();
        $log->token = Input::get('token');
        $log->total = $subscriptionAmount;
        $log->save();
   
        $appTransaction = AppTransaction::where('token',Input::get('token'))->firstOrFail();
        $appTransaction->status = 'success';
        $appTransaction->save();

        if(intval($subscriptionAmount)=== 20){
            $user->subscribeOneYear();
        }else if(intval($subscriptionAmount)=== 60){
            $user->subscribeFourYears();
        }

    }


      /**
     * handle error in the payement execution
     */
    public function processErrorPayment($currentUserId,$message){
       
        $log = new Log($currentUserId);

        if (empty(Input::get('PayerID')) || empty(Input::get('token'))) {

            \Session::put('error', $message);
            $log->status = 'failed';
            $log->message = 'transaction canceled';
            $log->paymentId = Input::get('PayerID');
            $log->token = Input::get('token');
            $log->save();
        }

    }

}