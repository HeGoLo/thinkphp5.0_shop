<?php
namespace app\common\model;
use think\Db;
use think\Request;
class Payment extends \think\Model
{
    /**
     * 
     * 微信支付统一下单
     * @param array $params
     * @param int $type
     * @return array 唤起支付所需参数
     */
	public static function wechat_build($params, $type = 0) 
	{
		vendor('wechatpay.WechatPay');
        $param_title = trim($params['title']);
		$out_trade_no = strval($params['tid']);
        $product_id = intval($params['product_id']);
        $total_fee = round($params['fee'] * 100, 0);

        $set = model('common')->getSec();
        $sec = iunserializer($set['sec']);
		$config = array('APPID'=>$sec['app_wechat']['appid'], 'MCHID'=>$sec['app_wechat']['merchid'], 'KEY'=>$sec['app_wechat']['apikey'], 'APPSECRET'=>$sec['app_wechat']['appsecret'], 'MERCHNAME'=>$sec['app_wechat']['merchname'], 'NOTIFY_URL'=>getHttpHost() . '/payment/wechat/notify');

        $order=array(
            'body'=>$param_title,
            'total_fee'=>$total_fee,
            'out_trade_no'=>$out_trade_no,
            'attach'=>$type,
            'trade_type'=>'APP',
            'product_id' => $product_id
        );

        $weixinpay=new \WechatPay();
        $weixinpay->config = $config;
        $response = $weixinpay->getAppPayParams($order);
        return $response;
	}

    /**
     * 
     * 支付宝支付统一下单
     * @param array $params
     * @param int $type
     * @return string 唤起支付所需签名
     */
	public static function alipay_build($params, $type = 0) 
	{
		vendor('alipay.aop.AopClient');
        vendor('alipay.aop.request.AlipayTradeAppPayRequest'); 
        $out_trade_no = strval($params['tid']);
        $product_id = intval($params['product_id']);
        $total_fee = round($params['fee'], 2);
        $param_title = trim($params['title']);
        
        $set = model('common')->getSec();
        $sec = iunserializer($set['sec']);

        $config = array('appId'=>$sec['app_alipay']['appid'], 'alipayrsaPublicKey'=>$sec['app_alipay']['public_key'], 'rsaPrivateKey'=>$sec['app_alipay']['private_key']);
        $aop = new \AopClient;
        $aop->gatewayUrl = "https://openapi.alipay.com/gateway.do";
        $aop->appId = $config['appId'];
        $aop->rsaPrivateKey = $config['rsaPrivateKey'];
        $aop->format = "json";
        $aop->charset = "UTF-8";
        $aop->signType = "RSA2";
        $aop->alipayrsaPublicKey = $config['alipayrsaPublicKey'];//对应填写

        $request = new \AlipayTradeAppPayRequest();
        //SDK已经封装掉了公共参数，这里只需要传入业务参数
        $bizcontent = json_encode(array(
            'body'=>$type,
            'subject' => $param_title,//支付的标题，
            'out_trade_no' => $out_trade_no,
            'timeout_express' => '30m',//過期時間（分钟）
            'total_amount' => $total_fee,//金額最好能要保留小数点后两位数
            'product_code' => 'QUICK_MSECURITY_PAY'
        ),JSON_UNESCAPED_UNICODE);
        $NOTIFY_URL = getHttpHost() . '/payment/alipay/notify';
        if($type == 0) {
            $NOTIFY_URL = getHttpHost() . '/payment/alipay/notify';
        }
        
        $request->setNotifyUrl($NOTIFY_URL);//你在应用那里设置的异步回调地址
        $request->setBizContent($bizcontent);
        $response = $aop->sdkExecute($request);//这里和普通的接口调用不同，使用的是sdkExecute
        return $response;
	}

    /**
     * 
     * 微信申请退款
     * @param array $params
     * @param int $type
     * @return string 唤起支付所需签名
     */
    public static function wxapp_refund($mid, $out_trade_no, $out_refund_no, $totalmoney, $refundmoney = 0, $app = true, $refund_account = false) 
    {
        if (empty($mid)) {
            return errormsg(-1, 'mid不能为空');
        }

        $member = model('member')->getMember($mid);
        if (empty($member)) {
            return errormsg(-1, '未找到用户');
        }

        $set = model('common')->getSysset('pay');

        if ($set['app_wechat'] != 1) {
            return errormsg(-1, '未开启微信支付');
        }

        $sec = model('common')->getSec();
        $sec = iunserializer($sec['sec']);
        $certs = array('cert' => $sec['app_wechat']['cert_file'], 'key' => $sec['app_wechat']['key_file']);
        $config = array('APPID'=>$sec['app_wechat']['appid'], 'MCHID'=>$sec['app_wechat']['merchid'], 'KEY'=>$sec['app_wechat']['apikey'], 'APPSECRET'=>$sec['app_wechat']['appsecret'], 'MERCHNAME'=>$sec['app_wechat']['merchname'], 'NOTIFY_URL'=>getHttpHost() . '/payment/wechat/notify');
        if (empty($config['MCHID'])) {
            return errormsg(-1, '未设置微信支付商户号');
        }

        if (empty($config['KEY'])) {
            return errormsg(-1, '未设置微信商户apikey');
        }

        if (empty($certs['cert']) || empty($certs['key'])) {
            return errormsg(-1, '未上传完整的微信支付证书，请到【系统设置】->【支付方式】中上传!');
        }
        $pars = array();
        $pars['nonce_str'] = random(32);
        $pars['out_trade_no'] = $out_trade_no;
        $pars['out_refund_no'] = $out_refund_no;
        $pars['total_fee'] = $totalmoney;
        $pars['refund_fee'] = $refundmoney;
        $pars['op_user_id'] = $config['MCHID'];

        if ($refund_account) {
            $pars['refund_account'] = $refund_account;
        }        

        vendor('wechatpay.WechatPay');
        $weixinpay=new \WechatPay();
        $weixinpay->config = $config;
        $response = $weixinpay->refund($pars);
        if(!empty($response)) {
            if(is_array($response) && $response['result_code'] != 'SUCCESS') {
                return errormsg(-1, $response['err_code_des']);
            } else {
                return errormsg(-1, $response);
            }            
        } else {
            return errormsg(-1, '操作失败');
        }
        return $response;
    }

    /**
     * 
     * 支付宝申请退款
     * @param array $params
     * @param int $type
     * @return string 唤起支付所需签名
     */
    public static function ali_refund($params) 
    {
        vendor('alipay.aop.AopClient');
        vendor('alipay.aop.request.AlipayTradeRefundRequest'); 
        $biz_content = array();
        $biz_content['out_trade_no'] = $params['out_trade_no'];
        $biz_content['refund_amount'] = $params['refund_amount'];
        $biz_content['refund_reason'] = $params['refund_reason'];
        $biz_content['out_request_no'] = $params['out_request_no'];
        $biz_content = array_filter($biz_content);
        $set = model('common')->getSec();
        $sec = iunserializer($set['sec']);

        $config = array('appId'=>$sec['app_alipay']['appid'], 'alipayrsaPublicKey'=>$sec['app_alipay']['public_key'], 'rsaPrivateKey'=>$sec['app_alipay']['private_key']);

        $aop = new \AopClient;
        $aop->gatewayUrl = 'https://openapi.alipay.com/gateway.do';
        $aop->appId = $config['appId'];
        $aop->rsaPrivateKey = $config['rsaPrivateKey'];
        $aop->alipayrsaPublicKey= $config['alipayrsaPublicKey'];
        $aop->signType = 'RSA2';
        $aop->postCharset='UTF-8';
        $aop->format='json';
        $aop->biz_content=json_encode($biz_content);;
        $request = new \AlipayTradeRefundRequest();
        $request->setBizContent(json_encode($biz_content,JSON_UNESCAPED_UNICODE));
        $result = $aop->execute ( $request); 
        $responseNode = str_replace(".", "_", $request->getApiMethodName()) . "_response";
        $resultCode = $result->$responseNode->code;
        if(!empty($resultCode)&&$resultCode == 10000){
            return errormsg(1, $result->$responseNode->sub_msg);
        } else {
            return errormsg(0, $result->$responseNode->sub_msg);
        }
    }

}