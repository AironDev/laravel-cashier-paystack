<?php
namespace Airondev\Cashier;

use Exception;
use Carbon\Carbon;
use Unicodeveloper\Paystack\Facades\Paystack;

class SubscriptionBuilder
{
    /**
     * The model that is subscribing.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $owner;
    /**
     * The name of the subscription.
     *
     * @var string
     */
    protected $name;
    /**
     * The name of the plan being subscribed to.
     *
     * @var string
     */
    protected $plan;
    /**
     * The number of trial days to apply to the subscription.
     *
     * @var int|null
     */
    protected $trialDays;
    /**
     * Indicates that the trial should end immediately.
     *
     * @var bool
     */
    protected $skipTrial = false;
    /**
     * The coupon code being applied to the customer.
     *
     * @var string|null
     */
    protected $coupon;
    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $owner
     * @param  string  $name
     * @param  string  $plan
     * @return void
     */
    public function __construct($owner, $name, $plan)
    {
        $this->name = $name;
        $this->plan = $plan;
        $this->owner = $owner;
    }
    /**
     * Specify the ending date of the trial.
     *
     * @param  int  $trialDays
     * @return $this
     */
    public function trialDays($trialDays)
    {
        $this->trialDays = $trialDays;
        return $this;
    }
    /**
     * Force the trial to end immediately.
     *
     * @return $this
     */
    public function skipTrial()
    {
        $this->skipTrial = true;
        return $this;
    }
    
    /**
     * Add a new Paystack subscription to the model.
     *
     * @param  array  $options
     * @return \Airondev\Cashier\Subscription
     * @throws \Exception
     */
    public function add(array $options = [])
    {
        if ($this->skipTrial) {
            $trialEndsAt = null;
        } else {
            $trialEndsAt = $this->trialDays ? Carbon::now()->addDays($this->trialDays) : null;
        }


        $params = [
            'name' => $this->name,
            'paystack_id'   => $options['id'] , // customer id
            'paystack_code' => $options['customer'], //customer code

            'paystack_plan' => $this->plan,
            'quantity' => 1,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => null,

            'subscription_code' => $options['subscription_code'],
            'subscription_id' => $options['id'],
            'paystack_status' => $options['status'],
            'paystack_plan' => $options['plan']['plan_code'],
            'quantity' => $options['quantity'] ?? '1',
            'email_token' => $options['email_token'],

            'authorization' => json_encode($options['authorization']),
            'payload' => json_encode($options),
            'customer' => json_encode($options['customer']),
            'next_payment_date' => Carbon::create( $options['next_payment_date']  ),
            'most_recent_invoice' => json_encode($options['most_recent_invoice']),
            'payments_count' =>  $options['payments_count'],
            'paystack_id' => $options['customer']['id'],
            'paystack_code' => $options['customer']['customer_code'],

            'amount' => $options['amount']/100,
            'currency' => $options['plan']['currency'],
            'type' => 'paystack',


            'updated_at' => now(),

        ];
        return $this->owner->subscriptions()->updateOrCreate(['paystack_plan' =>  $options['plan']['plan_code']], $params);

       
    }
    /**
     * Charge for a Paystack subscription.
     *
     * @param  array  $options
     * @return \Airondev\Cashier\Subscription
     * @throws \Exception
     */
    public function charge(array $options = [])
    {
        $options = array_merge([
            'plan' => $this->plan
        ], $options);
        return $this->owner->charge(100, $options);
    }
    /**
     * Create a new Paystack subscription.
     *
     * @param  string|null  $token
     * @param  array  $options
     * @return \Airondev\Cashier\Subscription
     * @throws \Exception
     */
    public function create($token = null, array $options = [])
    {
        $payload = $this->getSubscriptionPayload(
            $this->getPaystackCustomer(), $options
        );
        // Set the desired authorization you wish to use for this subscription here. 
        // If this is not supplied, the customer's most recent authorization would be used
        if (isset($token)) {
            $payload['authorization'] = $token;
        }
        $newSubscription = PaystackService::createSubscription($payload);
        //payload is not complete, fetch it again

        $subscription = PaystackService::fetchSubscription($newSubscription['data']['id']);


        if (! $subscription['status']) {
            throw new Exception('Paystack failed to create subscription: '.$subscription['message']);
        }
        
        return $this->add($subscription['data']);
    }
     /**
     * Get the subscription payload data for Paystack.
     *
     * @param  $customer
     * @param  array  $options
     * @return array
     * @throws \Exception
     */
    protected function getSubscriptionPayload($customer, array $options = [])
    {
        if ($this->skipTrial) {
            $startDate = Carbon::now();
        } else {
            $startDate =  $this->trialDays ? Carbon::now()->addDays($this->trialDays) : Carbon::now();
        }

        return [
            "customer" => $customer['customer_code'], //Customer email or code
            "plan" => $this->plan,
            "start_date" => $startDate->format('c'),
        ];
    }
    /**
     * Get the Paystack customer instance for the current user and token.
     *
     * @param  array  $options
     * @return $customer
     */
    protected function getPaystackCustomer(array $options = [])
    {
        if (! $this->owner->paystack_code) {
            $customer = $this->owner->createAsPaystackCustomer($options);
        } else {
            $customer = $this->owner->asPaystackCustomer();
        }
        return $customer;
    }
}
