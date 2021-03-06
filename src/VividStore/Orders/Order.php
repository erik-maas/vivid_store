<?php 
namespace Concrete\Package\VividStore\Src\VividStore\Orders;

use Concrete\Core\Foundation\Object as Object;
use Database;
use File;
use User;
use UserInfo;
use Core;
use Package;
use Concrete\Core\Mail\Service as MailService;
use Session;
use Group;
use Events;
use Config;


use \Concrete\Package\VividStore\Src\VividStore\Utilities\Price as Price;
use \Concrete\Package\VividStore\Src\Attribute\Key\StoreOrderKey as StoreOrderKey;
use \Concrete\Package\VividStore\Src\VividStore\Cart\Cart as VividCart;
use \Concrete\Package\VividStore\Src\VividStore\Product\Product as VividProduct;
use \Concrete\Package\VividStore\Src\VividStore\Orders\OrderItem as OrderItem;
use \Concrete\Package\VividStore\Src\Attribute\Value\StoreOrderValue as StoreOrderValue;
use \Concrete\Package\VividStore\Src\VividStore\Payment\Method as PaymentMethod;
use \Concrete\Package\VividStore\Src\VividStore\Customer\Customer as Customer;
use \Concrete\Package\VividStore\Src\VividStore\Orders\OrderEvent as OrderEvent;
use \Concrete\Package\VividStore\Src\VividStore\Orders\OrderStatus\History as OrderHistory;
use \Concrete\Package\VividStore\Src\VividStore\Orders\OrderStatus\OrderStatus;

defined('C5_EXECUTE') or die(_("Access Denied."));
class Order extends Object
{
    public static function getByID($oID) {
        $db = Database::get();
        $data = $db->GetRow("SELECT * FROM VividStoreOrders WHERE oID=?",$oID);
        if(!empty($data)){
            $order = new Order();
            $order->setPropertiesFromArray($data);
        }
        return($order instanceof Order) ? $order : false;
    }  
    public function getCustomersMostRecentOrderByCID($cID)
    {
        $db = Database::get();
        $data = $db->GetRow("SELECT * FROM VividStoreOrders WHERE cID=? ORDER BY oID DESC",$cID);
        return Order::getByID($data['oID']);
    }
    public function add($data,$pm)
    {
        $taxBased = Config::get('vividstore.taxBased');
        $taxlabel = Config::get('vividstore.taxName');

        $this->set('taxlabel',$taxlabel);

        $taxCalc = Config::get('vividstore.calculation');

        $db = Database::get();
        
        //get who ordered it
        $customer = new Customer();
        
        //what time is it?
        $dt = Core::make('helper/date');
        $now = $dt->getLocalDateTime();
        
        //get the price details
        $shipping = VividCart::getShippingTotal();
        $shipping = Price::formatFloat($shipping);
        $taxvalue = VividCart::getTaxTotal();
        $taxName = Config::get('vividstore.taxName');
        $total = VividCart::getTotal();
        $total = Price::formatFloat($total);

        $tax = 0;
        $taxIncluded = 0;

        if ($taxCalc == 'extract') {
            $taxIncluded = $taxvalue;
        }  else {
            $tax = $taxvalue;
        }
        $tax = Price::formatFloat($tax);
        
        //get payment method
        $pmID = $pm->getPaymentMethodID();

        //add the order
        $vals = array($customer->getUserID(),$now,$pmID,$shipping,$tax,$taxIncluded,$taxName,$total);
        $db->Execute("INSERT INTO VividStoreOrders(cID,oDate,pmID,oShippingTotal,oTax,oTaxIncluded,oTaxName,oTotal) VALUES (?,?,?,?,?,?,?,?)", $vals);
        $oID = $db->lastInsertId();
        $order = Order::getByID($oID);
        $order->updateStatus(OrderStatus::getStartingStatus()->getHandle());
        $order->setAttribute("email",$customer->getEmail());
        $order->setAttribute("billing_first_name",$customer->getValue("billing_first_name"));
        $order->setAttribute("billing_last_name",$customer->getValue("billing_last_name"));
        $order->setAttribute("billing_address",$customer->getValueArray("billing_address"));
        $order->setAttribute("billing_phone",$customer->getValue("billing_phone"));
        $order->setAttribute("shipping_first_name",$customer->getValue("shipping_first_name"));
        $order->setAttribute("shipping_last_name",$customer->getValue("shipping_last_name"));
        $order->setAttribute("shipping_address",$customer->getValueArray("shipping_address"));

        $customer->setLastOrderID($oID);

        //add the order items
        $cart = Session::get('cart');

        foreach ($cart as $cartItem) {
            $taxvalue = VividCart::getTaxProduct($cartItem['product']['pID']);
            $tax = 0;
            $taxIncluded = 0;

            if ($taxCalc == 'extract') {
                $taxIncluded = $taxvalue;
            }  else {
                $tax = $taxvalue;
            }

            $productTaxName = $taxName;

            if ($taxvalue == 0) {
                $productTaxName = '';
            }

            OrderItem::add($cartItem,$oID,$tax,$taxIncluded,$productTaxName);
            $product = VividProduct::getByID($cartItem['product']['pID']);
            if ($product && $product->hasUserGroups()) {
                $usergroupstoadd = $product->getProductUserGroups();
                foreach ($usergroupstoadd as $id) {
                    $g = Group::getByID($id);
                    if ($g) {
                        $customer->getUserInfo()->enterGroup($g);
                    }
                }
            }
        }
        
        if (!$customer->isGuest()) {
            //add user to Store Customers group
            $group = \Group::getByName('Store Customer');
            if (is_object($group) || $group->getGroupID() < 1) {
                $customer->getUserInfo()->enterGroup($group);
            }
        }

        // create order event and dispatch
        $event = new OrderEvent($order);
        Events::dispatch('on_vividstore_order', $event);
        
        //send out the alerts
        $mh = new MailService();
        $pkg = Package::getByHandle('vivid_store');

        $fromEmail = Config::get('vividstore.emailalerts');
        if(!$fromEmail){
            $fromEmail = "store@".$_SERVER['SERVER_NAME'];
        }
        $alertEmails = explode(",", Config::get('vividstore.notificationemails'));
        $alertEmails = array_map('trim',$alertEmails);
        
            //receipt
            $mh->from($fromEmail);
            $mh->to($customer->getEmail());

            $mh->addParameter("order", $order);
            $mh->addParameter("taxbased", $taxBased);
            $mh->addParameter("taxlabel", $taxlabel);
            $mh->load("order_receipt","vivid_store");
            $mh->sendMail();

            //order notification
            $mh->from($fromEmail);
            foreach($alertEmails as $alertEmail){
                $mh->to($alertEmail);
            }
            $mh->addParameter("order", $order);
            $mh->addParameter("taxbased", $taxBased);
            $mh->addParameter("taxlabel", $taxlabel);

            $mh->load("new_order_notification","vivid_store");
            $mh->sendMail();
            
        
        Session::set('cart',null);
    }
    public function remove()
    {
        $db = Database::get();
        $db->Execute("DELETE FROM VividStoreOrders WHERE oID=?",$this->oID);
        $db->Execute("DELETE FROM VividStoreOrderItems WHERE oID=?",$this->oID);
    }
    public function getOrderItems()
    {
        $db = Database::get();    
        $rows = $db->GetAll("SELECT * FROM VividStoreOrderItems WHERE oID=?",$this->oID);
        $items = array();

        foreach($rows as $row){
            $items[] = OrderItem::getByID($row['oiID']);
        }

        return $items;
    }
    public function getOrderID(){ return $this->oID; }
    public function getPaymentMethodName() {
        $pm = PaymentMethod::getByID($this->pmID); 
        if(is_object($pm)){  
            return $pm->getPaymentMethodName();
        }
    }
    public function getStatus(){ return $this->oStatus; }
    public function getCustomerID(){ return $this->cID; }
    public function getOrderDate(){ return $this->oDate; }
    public function getTotal() { return $this->oTotal; }
    public function getSubTotal()
    {
        $items = $this->getOrderItems();
        $subtotal = 0;
        if($items){
            foreach($items as $item){
                $subtotal = $subtotal + ($item->oiPricePaid * $item->oiQty);
            }
        }
        return $subtotal;
    }
    public function getTaxTotal() { return $this->oTax + $this->oTaxIncluded; }
    public function getShippingTotal() { return $this->oShippingTotal; }
    
    public function updateStatus($status)
    {
        OrderHistory::updateOrderStatusHistory($this, $status);
    }
    public function getStatusHistory() {
        return OrderHistory::getForOrder($this);
    }
    public function setAttribute($ak, $value)
    {
        if (!is_object($ak)) {
            $ak = StoreOrderKey::getByHandle($ak);
        }
        $ak->setAttribute($this, $value);
    }
    public function getAttribute($ak, $displayMode = false) {
        if (!is_object($ak)) {
            $ak = StoreOrderKey::getByHandle($ak);
        }
        if (is_object($ak)) {
            $av = $this->getAttributeValueObject($ak);
            if (is_object($av)) {
                return $av->getValue($displayMode);
            }
        }
    }
    public function getAttributeValueObject($ak, $createIfNotFound = false) {
        $db = Database::get();
        $av = false;
        $v = array($this->getOrderID(), $ak->getAttributeKeyID());
        $avID = $db->GetOne("SELECT avID FROM VividStoreOrderAttributeValues WHERE oID = ? AND akID = ?", $v);
        if ($avID > 0) {
            $av = StoreOrderValue::getByID($avID);
            if (is_object($av)) {
                $av->setOrder($this);
                $av->setAttributeKey($ak);
            }
        }

        if ($createIfNotFound) {
            $cnt = 0;
        
            // Is this avID in use ?
            if (is_object($av)) {
                $cnt = $db->GetOne("SELECT COUNT(avID) FROM VividStoreOrderAttributeValues WHERE avID = ?", $av->getAttributeValueID());
            }
            
            if ((!is_object($av)) || ($cnt > 1)) {
                $av = $ak->addAttributeValue();
            }
        }
        
        return $av;
    }
}
