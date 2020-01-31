<?php
/*
Plugin Name: 	FO Pay
Plugin URI: 	https://ftqq.com/fopay
Description: 	基于 FO 的付费阅读
Version: 		1.2
Author: 		Easy
Author URI: 	https://weibo.com/easy
License: 		GPL2
License URI:  	https://www.gnu.org/licenses/gpl-2.0.html
 */

// 首先我们在后台添加设置菜单
add_action( 'admin_menu' ,  'fo_pay_menu' );

function fo_pay_menu()
{
   add_options_page( '佛系支付插件 for FIBOS', 'Fo支付设置', 'manage_options' , '_fo_pay_settings' , 'fo_pay_settings_page' );
}

add_action('admin_init', function()
{
    register_setting('fo-pay-option-page' , 'fo-pay-options'
    );

    add_settings_section(
		'fo-pay-option-page-section',
		'FO 收款账户设置',
		'section_title',
		'fo-pay-option-page'
    );
    
    function section_title()
    {
        echo "在下方配置您的FO账户后，即可进行收款。";
    }

    add_settings_field(
        'fo_pay_account',
        "FO 收款账户",
        'fo_pay_account_render',
        'fo-pay-option-page',
        'fo-pay-option-page-section'
    );

    add_settings_field(
        'fo_pay_idstr',
        "博客唯一码",
        'fo_pay_idstr_render',
        'fo-pay-option-page',
        'fo-pay-option-page-section'
    );

});

// 然后我们在文章编辑页面添加自定义字段
// 手工添加即可，不通过程序来搞


// 显示时，根据权限过滤内容
add_filter('the_content', function( $content )
{
    $meta = get_post_meta(get_the_ID());
    $user = wp_get_current_user();

    if( intval($meta['fo-usdt-price'][0]) > 0 )
    {
        $paid_uids = get_post_meta( get_the_ID() , '_paid_uids' )[0];

        if( $user && $paid_uids )
        {
            
            if( in_array( $user->ID , $paid_uids ) )
            {
                // logit("在里半啊".print_r( $user , 1 )) ;
                $content = str_replace( [ '[pay]' , '[/pay]' ] , '' , $content );
                return $content;
            }
                
        }
        
        
        // 开始进行付费控制
        $price = intval($meta['fo-usdt-price'][0])/100;
        if( preg_match( "/\[pay](.+?)\[\/pay]/is" , $content  ) )
        {
            $content = preg_replace( "/\[pay](.+?)\[\/pay]/is" , get_pay_notice( get_the_ID() , $price ) , $content );
        }
        else
        {
            //没有找到[pay]标记
            // 全部隐藏
            $content = get_pay_notice( get_the_ID() , $price );
        }


        return $content;
    }
    else
    {
        $content = str_replace( [ '[pay]' , '[/pay]' ] , '' , $content );
        return $content;
    }

});

// 然后添加 cron 每隔 5 秒检查一次到账情况
// 定义五秒间隔

add_filter( 'cron_schedules', function ( $schedules ) {
    
    $schedules['five_seconds'] = array(
        'interval' => 5,
        'display'  => esc_html__( 'Every Five Seconds' ),
    );
 
    return $schedules;
} );


if ( ! wp_next_scheduled( 'fo_cron_hook' ) ) {
    wp_schedule_event( time(), 'five_seconds', 'fo_cron_hook' );
}

add_action( 'fo_cron_hook', 'fo_cron_exec' );

function fo_cron_exec()
{
    $log = [];
    // 开始检查支付情况
    // 首先需要获得收款账号
    $option = get_option( "fo-pay-options" );
    if( !($option && $option['account']) ) return logit("账户不存在");

    $account = $option['account'];
    $memo_prefix = 'WPFO-'.$option['idstr'];

    // 然后构造 http 请求，获取最新的支付情况
    if( !$txs = fo_get_user_tx( $account , $memo_prefix )) return logit("没有查询到交易");

    $to_change = [];

    foreach( $txs as $tx )
    {
        $reg = '/^WPFO\-233\-([0-9]+)\-([0-9]+)$/is';
        if( preg_match( $reg , $tx['memo'] , $out ) )
        {
            list( , $post_id , $uid ) = $out;
            $post_meta = get_post_meta( $post_id );
            /**
              Array
                (
                    [_edit_lock] => Array
                        (
                            [0] => 1580467639:1
                        )

                    [fo-usdt-price] => Array
                        (
                            [0] => 1
                        )

                    [_edit_last] => Array
                        (
                            [0] => 1
                        )

                )
            */
           

            if( isset( $post_meta['fo-usdt-price'] ) && intval( $post_meta['fo-usdt-price'][0] ) >= 0 )
            {
                logit( "取到了meta里的 price ". $post_meta['fo-usdt-price'][0]);

                $paid_price = explode(" " , $tx['quantity']['quantity'])[0]*100;

                if( $paid_price >= $post_meta['fo-usdt-price'][0] )
                {
                    logit( "支付价格为 ".$paid_price);

                    // 将UID加入到 post 的 _paid_uids 里边
                    $to_change[$post_id][] = $uid;
                    
                }
            }
            
            // logit("post meta $post_id $uid ".print_r( $post_meta , 1 ));

        }
        else
        {
            logit("not match" , $tx['memo']);
        }
    }

    if( count( $to_change ) > 0 )
    {
        foreach( $to_change as $the_post_id => $the_uids )
        {
            $paid_uids = get_post_meta( $the_post_id , '_paid_uids' );

            $old_paid_uids = $paid_uids;

            $paid_uids = array_merge( $paid_uids , $the_uids );
            $paid_uids = array_unique( $paid_uids );


            update_post_meta( $the_post_id , '_paid_uids' , $paid_uids , $old_paid_uids  );

            logit( "updated " . print_r( get_post_meta( $the_post_id , '_paid_uids' ) , 1 ) );

        }
    }

    logit( "to change " . print_r( $to_change , 1 ) );

    /*
    $paid_uids = get_post_meta( $post_id , '_paid_uids' );

                    $old_paid_uids = $paid_uids;

                    if( count( $paid_uids ) < 1 ) $paid_uids = [ $uid ];
                    else
                    {
                        if (!in_array($uid, $paid_uids)) {
                            $paid_uids = array_push($paid_uids, $uid);

                            logit("new paid_uids" . print_r($paid_uids, 1));
                        }
                        else
                        {
                            logit( "already" );
                        }

                    }

                    logit( "new paid_uids" . print_r( $paid_uids , 1 ) );

                    update_post_meta( $post_id , '_paid_uids' , $paid_uids , $old_paid_uids  );

                    logit( "updated " . print_r( get_post_meta( $post_id , '_paid_uids' ) , 1 ) );

                    logit("updated");
                    
    */
    
    
    
    return true;
    // 
    // sc_send( "cron执行了" );
}

function logit( $content )
{
    file_put_contents( __DIR__ . "/log.txt" , $content . "\r\n" , FILE_APPEND );
}



function fo_pay_account_render()
{
    $options = get_option('fo-pay-options');
    if (!isset($options['account'])) {
        $options['account'] = '';
    }
    ?>
    <input type='text' name='fo-pay-options[account]' value='<?php echo $options['account']; ?>'>
    <span class="description">下载 FO 钱包即可免费生成收款账户</span>
    <?php
}

function fo_pay_idstr_render()
{
    $options = get_option('fo-pay-options');
    if (!isset($options['idstr'])) {
        $options['idstr'] = '';
    }
    ?>
    <input type='text' name='fo-pay-options[idstr]' value='<?php echo $options['idstr']; ?>'>
    <span class="description">一串数字，建议3到6位长，用于区分支付博客</span>
    <?php
}


function fo_pay_settings_page()
{
    echo '<div class="wrap"><h2>支付配置</h2>';
    echo "<form action='options.php' method='post'>";
    settings_fields('fo-pay-option-page');
    do_settings_sections('fo-pay-option-page');
    submit_button();
    echo '</form>';
    echo '</div>';
}

function get_pay_notice( $post_id , $price = 1 )
{
    $option = get_option( "fo-pay-options" );
    $account = $option && $option['account'] ? $option['account'] : 'phpisthetest';
    $idstr = $option && $option['idstr'] ? $option['idstr'] : '0';
    
    $user = wp_get_current_user();

    if( $user && $user->ID > 0 )
    {
        $url = "https://wallet.fo/Pay?params=" . urlencode( $account ) . ",FOUSDT,eosio,". $price ."," . urlencode( 'WPFO-'.$idstr.'-'.$post_id . "-" . $user->ID );
        
        $notice = "此内容需要支付后才能阅读。请<a href='https://wallet.fo' target='_blank'>下载 FO 钱包</a>，扫描二维码支付。完成后，稍等三到五分钟刷新本页面。 ";

        $notice .= '<img style="margin:20px;" src="https://chart.googleapis.com/chart?chs=200x200&cht=qr&chld=H|1&chl='.urlencode($url).'" />';

    }
    else
    {
        $notice = "此内容需要支付后才能阅读，请先登入后进行支付";
    }

    return $notice;

    
}

function fo_get_user_tx( $account , $memo_prefix , $token = 'FOUSDT@eosio' )
{
    logit( "prefix=" . $memo_prefix );
    
    $ret = false;

    $url = 'http://elb-tracker-api-1674205173.ap-northeast-1.elb.amazonaws.com/1.1';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/graphql']);

    $postdata = '{
        find_fibos_tokens_action(
         order:"-id"
         where:{
                         account_to_id: "'.$account.'",
                         contract_action:{
                             in:["eosio.token/transfer","eosio.token/extransfer"]
                         }
                     }
        ){
                     action{
                         rawData
                         transaction
                        {
                            block
                            {
                                status
                            }
                        }
                     }
         token_from{
          token_name
         }
        }
    }';

    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
    $data = curl_exec($ch);
    curl_close($ch);

    // logit("txdata".print_r( $data , 1 ));

    if( !$data_array = json_decode( $data , true )) return false;

    // 
    foreach( $data_array['data']['find_fibos_tokens_action'] as $item ){
        // 检测token类型
        if( $item['token_from']['token_name'] == $token )
        {
            // 检测交易状态
            if( $item['action']['transaction']['block']['status'] == 'noreversible' )
            {
                // 检测订单号
                if( strpos( trim($item['action']['rawData']['act']['data']['memo']) , $memo_prefix) !== false )
                {
                    $ret[] = $item['action']['rawData']['act']['data'];
                    
                }
                
                
                // print_r( $item );
            }
        }
    }

    return $ret;

}



 