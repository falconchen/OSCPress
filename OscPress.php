<?php
/*
Plugin Name: OscPress
Plugin URI: https://www.cellmean.com/oscpress
Description: Wordprss站点更新文章时, 将内容自动发布到osc博客和动弹
Version: 1.0
Author: Falcon
Author URI: https://www.cellmean.com
*/
$oscPress = new OscPress();

class OscPress{

    /**
     * 初始化
     */
    public function __construct(){

        $this->api_site = 'https://www.oschina.net'; // osc api的基本前缀

        $this->dir = __DIR__; // 当前插件的物理地址

        $this->state = __CLASS__ . '_state'; // 用于oauth 授权的安全鉴权的参数

        $this->callback_url = add_query_arg('callback', 'oscpress', site_url());

        // $client_key_arr = $this->_get_client_key();

        add_action( 'admin_menu', array( $this,'admin_menu'));

        add_action( 'admin_init', array($this,'admin_init') );

        add_action('query_vars', array($this, 'add_query_vars'));

        add_action("parse_request", array($this, 'callback'));

        add_action('publish_post', array($this,'publish_post'),10,2); // 发布一般文章时执行

        add_action('publish_page', array($this,'publish_post'),10,2); // 发布page时执行

        add_action( 'add_meta_boxes', array($this,'add_meta_boxes') );

        add_action('init',array($this,'init'));
    }


    public function init() {
        //时钟
        if(isset($_GET['osc_action'])) {
            if($_GET['osc_action']=='clock') {
                $settings = $this->_get_oscpress_settings();
                if($settings['clock']){
                    $timestamp = current_time( 'timestamp' );
                    $content = '';
                    for($i=0;$i<date('g',$timestamp);$i++){
                        $content .='铛...';
                    }
                    $content .= ' 现在是北京时间 '. date('H:00',$timestamp) .' #敬请对时#';

                    $this->_send_tweet($content);
                }
            }
            exit;
        }

    }

    public function admin_menu() {
        add_options_page('OscPress','OscPress','manage_options',"oscpress_admin_settings",array($this,'admin_setting'));
        //add_submenu_page('options-general.php', 'OscPress','OscPress', 10, __FILE__, array($this,'admin_setting'));
    }


    public function admin_init() {

        if($_REQUEST['action'] && $_REQUEST['action']=='clear_osc_authorize'){
            if (check_admin_referer('clear_osc_authorize')) {
                if($this->_clear_authorize()){
                    add_settings_error('oscpress_settings', 'clear_osc_authorize_failed', "授权信息清除成功", 'updated');
                }
            }
        }

        register_setting( 'oscpress_settings_group', 'oscpress_settings',array($this,'sanitize_callback') );

        /*
		This creates a “section” of settings.
		The first argument is simply a unique id for the section.
		The second argument is the title or name of the section (to be output on the page).
		The third is a function callback to display the guts of the section itself.
		The fourth is a page name. This needs to match the text we gave to the do_settings_sections function call.
	    */
        add_settings_section('oscpress_settings', 'OscPress设置', array($this,'settings_section_text'), 'oscpress');

        /*
         The first argument is simply a unique id for the field.
        The second is a title for the field.
        The third is a function callback, to display the input box.
        The fourth is the page name that this is attached to (same as the do_settings_sections function call).
        The fifth is the id of the settings section that this goes into (same as the first argument to  add_settings_section).
        第六个参数是自定义传入回调函数的参数，数组类型
        */
        add_settings_field('appid', '应用ID', array($this,'input_text'), 'oscpress', 'oscpress_settings',array(
            "field" =>"appid", "label"=>"应用ID","placeholder"=>"请输入应用ID"
        ));
        add_settings_field('appsecret', '应用私钥', array($this,'input_text'), 'oscpress', 'oscpress_settings',array(
            "field" =>"appsecret", "label"=>"应用私钥","placeholder"=>"请输入应用私钥"
        ));

        // 增加其他设置项
        // 为同步到osc的博客的文章 增加前置,后置内容
        $depcription = "%%post_link%%: 文章短链接;<br/> %%title%%: 文章标题;<br/>%%home_url%%:站点地址;<br>%%site_name%%:站点名称<br/>%%post_thumb%%: 缩略图url";
        add_settings_field('prefix_content', '同步到osc的文章前置内容', array($this,'textarea'), 'oscpress', 'oscpress_settings',array(
            "field" =>"prefix_content", "label"=>"同步到osc的文章前置内容",
            "description" => $depcription
        ));

        add_settings_field('postfix_content', '同步到osc的文章后置内容', array($this,'textarea'), 'oscpress', 'oscpress_settings',array(
            "field" =>"postfix_content", "label"=>"同步到osc的文章后置内容",
            "description" => $depcription
        ));

        // 动弹模板
        add_settings_field('tweet_template', '发送到osc的动弹模板', array($this,'textarea'), 'oscpress', 'oscpress_settings',array(
            "field" =>"tweet_template", "label"=>"发送到osc的动弹模板",
            "description" => $depcription
        ));

        // div包裹显示链接图片,防止同步到osc上的排版问题
        add_settings_field('display_link_image_in_div', 'div包裹显示链接图片', array($this,'checkbox'),'oscpress','oscpress_settings',array(
            "field" =>"display_link_image_in_div", "label"=>"div包裹显示链接图片",
            "description" => '修正同步到osc上的图片排版问题'
        ));

        add_settings_field('clock', '报时动弹', array($this,'checkbox'),'oscpress','oscpress_settings',array(
            "field" =>"clock", "label"=>"报时动弹",
            "description" => '报时动弹'
        ));



    }

    protected function _get_oscpress_settings(){

        $settings = get_option( 'oscpress_settings' );
        if(empty($settings)) {
            $settings = include 'settings.php';
            return $settings;
        }else{
            return $settings;
        }

    }
    public function checkbox($arr) {

        $settings = $this->_get_oscpress_settings();
        $field = $arr['field'];
        $value = intval( $settings[$field] );
        $if_checked = $value ? 'checked' :"";
        echo "<label class=\"oscpress_settings\" for=\"{$arr['field']}\"><input $if_checked type='checkbox' id='{$arr['field']}' name='oscpress_settings[$field]' value='1'}\"  /><em>{$arr['description']}</em></label>";
    }


    public function textarea($arr) {
        $settings = $this->_get_oscpress_settings();
        $field = $arr['field'];
        $value = esc_textarea( $settings[$field] );

        echo "<label class=\"oscpress_settings\" for=\"{$arr['field']}\"><textarea type='text' id='{$arr['field']}' name='oscpress_settings[$field]' rows=\"8\" cols=\"80\"/>{$value}</textarea><br><em>{$arr['description']}</em></label>";

    }
    public function input_text($arr){

        $settings = $this->_get_oscpress_settings();
        $field = $arr['field'];
        $value = esc_attr( $settings[$field] );

        echo "<label class=\"oscpress_settings\" for=\"{$arr['field']}\"><input size=\"50\" type='text' id='{$arr['field']}' name='oscpress_settings[$field]' value='$value' placeholder=\"{$arr['placeholder']}\" /></label>";


    }

    /**
     * 验证和洁净化函数
     */
    public function sanitize_callback($inputs){

        if( strlen($inputs['appid']) !== 20 ) {
            add_settings_error('oscpress_settings', 'oscpress_failed', "应用ID必须为20位", 'error');
            $settings = (array) get_option( 'oscpress_settings' );
            $inputs['appid'] = isset($settings['appid']) ? $settings['appid'] : "";
        }

        return $inputs;

    }

    public function settings_section_text(){
        echo "<hr/>";
        $authorize_url = $this->_generate_authorize_url();

        if(false === $authorize_url){
            // 未填写应用id和私钥
            echo "<em>填写应用的id及私钥</em>";
        }elseif( $access_token = $this->_get_access_token() ) {
            // 已获取access token,显示个人信息
            $response = $this->_get_openapi_user();
            if(is_wp_error($response)) {
                $this->_show_error_info($response);
                return;
            }

            $info_obj = json_decode($response['body']);
?>
            <span>

                <img align="absbottom" src="<?php echo $info_obj->avatar; ?>"/><br/>
                <p>
                    <a href="<?php echo $info_obj->url; ?>" target="_blank"><?php echo $info_obj->name; ?></a>
                     &nbsp;|&nbsp;
                    <a href="<?php echo $this->_clear_authorize_url(); ?>">清除授权</a>
                </p>
            </span>

            <p>Access Token: <?php echo $access_token;?></p><hr/>
<?php

        }else{
            // 未授权,显示授权链接
            printf("<em><a href='%s'>点击授权</a></em>",$authorize_url);
        }

    }

    public function admin_setting(){

?>

        <div class="wrap">
            <form action="options.php" method="POST">
                <?php settings_fields('oscpress_settings_group'); ?>
                <?php do_settings_sections('oscpress'); ?>
                <?php submit_button(); ?>
            </form>
        </div>

    <?php
    }

    // 增加一个新的公共查询函数
    public function add_query_vars($public_query_vars) {

        $public_query_vars[] = 'callback';
        return $public_query_vars;

    }

    // osc api 的回调函数
    public function callback($request)
    {
        if (isset($request->query_vars['callback']) && $request->query_vars['callback'] == 'oscpress') {
            $this->_callback();
            exit();
        };

    }

    // 实际执行的回调
    protected function _callback(){
        if ( isset($_GET['code']) && isset($_GET['state']) && $this->_verify_state($_GET['state'])) {
            $code = $_GET['code'];

            $url = $this->api_site . '/action/openapi/token';
            $settings = get_option( 'oscpress_settings' );
            $args = array(
                'client_id' => $settings['appid'],
                'client_secret' => $settings['appsecret'],
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->callback_url,
                'code' => $code,
                'dataType' => 'json'
            );

            $response = wp_remote_post($url, array('body' => $args));
            if (!is_wp_error($response) && $response['response']['code'] == 200) {
                $this->_save_token($response['body']);
                $redirect_url = admin_url('admin.php?page=oscpress_admin_settings');
                wp_redirect($redirect_url);
                printf('<script>window.location.href="%s";</script>', $redirect_url);

            } elseif (is_wp_error($response)) {
                echo "请求出错:" . $response->get_error_message();
            } else {
                echo "未知错误";

            }

        } else {
            echo "回调失败: 参数错误";
        }
    }


    // 生成引导的验证url
    protected function _generate_authorize_url() {

        $settings = $this->_get_oscpress_settings();
        if($settings['appid'] == "") {
            return false;
        }
        $authorize_url = $this->api_site . '/action/oauth2/authorize';
        $args = array(
            'response_type' => 'code',
            'client_id' => $settings['appid'],
            'redirect_uri' => $this->callback_url,
            'state' => wp_create_nonce($this->_state),
        );
        $authorize_url .= '?' . http_build_query($args);

        return $authorize_url;
    }

    // 验证回调后的state参数
    protected function _verify_state($state)
    {

        if ($state !== wp_create_nonce($this->_state)) {
            return false;
        }
        return true;
    }

    //保存access token
    protected function _save_token($token_arr)
    {
        update_option('oscpress_token', $token_arr);
        update_option('oscpress_token_update_at', time());
        return true;
    }

    // 获取存储的access token
    protected function _get_access_token(){

        $json_str = get_option('oscpress_token',false);
        if(false === $json_str) {
            return false;
        }

        $obj = json_decode($json_str);
        $update_ts = get_option('oscpress_token_update_at',0);
        if($obj->expires_in + $update_ts <= time() ){ // 过期
            return false;
        }

        return $obj->access_token;
    }

    // 获得用户信息
    protected function _get_openapi_user()
    {


        $url = $this->api_site . '/action/openapi/user';
        $args = array(
            'access_token' => $this->_get_access_token(),
            'dataType' => 'json'
        );
        $response = wp_remote_post($url, array('body' => $args));
        return $this->_check_api_error($response);

    }



    // 生成取消授权url
    protected function _clear_authorize_url(){
        return admin_url('admin.php?page=oscpress_admin_settings&action=clear_osc_authorize&_wpnonce=' . wp_create_nonce('clear_osc_authorize'));
    }

    // 清除授权信息
    protected function _clear_authorize()
    {
        return delete_option('oscpress_token') && delete_option('oscpress_token_update_at');
    }

    // 发布文章时同步到osc
    public function publish_post($ID,$post) {


        if( isset($_POST['oscpress_syn_enable']) && $_POST['oscpress_syn_enable'] == 0){
            the_content();
            return ; // 不同步到osc博客
        }
        $settings = $this->_get_oscpress_settings();
        $post_link = apply_filters('oscpress_sync_link',wp_get_shortlink($ID),$ID); // 发布到osc动弹的文章链接
        $home_url = home_url('/');

        $site_name = get_bloginfo('name');
        $post_thumb = $this->_get_thumb_url();
        $post_arr = array();
        $tags = "";
        $post_arr['title'] = $post->post_title;

        $prefix_content = str_replace(
            array('%%post_title%%','%%post_link%%','%%home_url%%','%%site_name%%','%%post_thumb%%'),
            array($post_arr['title'],$post_link,$home_url,$site_name,$post_thumb),
            $settings['prefix_content']
        );
        $postfix_content = str_replace(
            array('%%post_title%%','%%post_link%%','%%home_url%%','%%site_name%%','%%post_thumb%%'),
            array($post_arr['title'],$post_link,$home_url,$site_name,$post_thumb),
            $settings['postfix_content']
        );
        /*
         *如图： <a href="https://www.cellmean.com/wp-content/uploads/2016/07/屏幕截图-2016-07-08-22.00.19-1.png"><img alt="屏幕截图 2016-07-08 22.00.19" height="239" src="http://static.oschina.net/uploads/img/201607/12155338_Krda.png" width="300" /></a>
         * 将此类图片作为block元素显示
         */

        //$post_content = preg_replace("#<a href=#")
        if($settings['display_link_image_in_div']){
            $post_content = preg_replace('#(<a[^<>]*><img[^<>]*></a>)#iUs', "<div>$0</div>",$post->post_content);
        }else{
            $post_content = $post->post_content;
        }
        $post_content= apply_filters('the_content',$post_content);
        $post_arr['content'] = $prefix_content . $post_content . $postfix_content;

        $post_arr['abstracts'] = get_the_excerpt($ID);
        $tags_arr = wp_get_post_tags($ID);
        if(!empty($tags_arr)){
            foreach($tags_arr as $tag) {
                $tags .= $tag->name .',';
            }
            $tags = rtrim($tags,',');
        }
        $post_arr['tags'] = $tags;
        $post_arr = array_merge($post_arr,$_POST['oscpress_syn']);
        unset($post_arr['tweet_enable']);
        $response = $this->_blog_pub($post_arr);
        $oscpress_syn = $_POST['oscpress_syn'];
        $oscpress_syn['error_msg'] = "ok";
        $oscpress_syn['timestamp'] = current_time('timestamp');


        if( $_POST['oscpress_syn']['tweet_enable']) { // 独立出来,不依赖博客同步是否成功,因为发现同步成功也TM返回https_reqest_failed.

            // $tweet_template = "我发布了一篇文章:<<%%post_title%%>>,传送门:%%post_link%%, 自豪地使用 #OscPress# 同步 ";
            $tweet_template = $settings['tweet_template'];
            $tweet_content = str_replace(
                array('%%post_title%%','%%post_link%%','%%home_url%%','%%site_name%%','%%post_thumb%%'),
                array($post_arr['title'],$post_link,$home_url,$site_name,$post_thumb),
                $tweet_template
            );

            if($image_id = get_post_thumbnail_id($post->ID)){
                $image_path = get_attached_file( $image_id );
                $response2  = $this->_send_img_tweet($tweet_content,$image_path,true);
            }else{
                $response2  = $this->_send_tweet($tweet_content);
            }

        }

        if(!is_wp_error($response) && !is_wp_error($response2)){

            $oscpress_syn['error_msg'] = "ok";

        }else{

            $oscpress_syn['error_msg'] = $response->get_error_code();
        }

        update_post_meta($ID,'_oscpress_syn',$oscpress_syn);
    }

    // open api发布博客
    protected function _blog_pub($args = array()){
        /**
         * access_token 	true 	string 	oauth2_token获取的access_token
        title 	true 	string 	博客标题
        content 	true 	string 	博客内容
        save_as_draft 	false 	int 	保存到草稿 是：1 否：0 	0
        catalog 	false 	string 	博客分类, 工作日志:304043,日常记录:304044,转帖的文章:304045
        abstracts 	false 	string 	博客摘要
        tags 	false 	string 	博客标签，用逗号隔开
        classification 	true 	int 	系统博客分类 	0
        428602>移动开发
        428612>前端开发
        428640>服务端开发/管理
        429511>游戏开发
        428609>编程语言
        428610>数据库
        428611>企业开发
        428647>图像/多媒体
        428613>系统运维
        428638>软件工程
        428639>云计算
        430884>开源硬件
        430381>其他类型
        type 	false 	int 	原创：1、转载：4 	1
        origin_url 	false 	string 	转载的原文链接
        privacy 	false 	string 	公开：0、私有：1 	0
        deny_comment 	false 	string 	允许评论：0、禁止评论：1 	0
        auto_content 	false 	string 	自动生成目录：0、不自动生成目录：1 	0
        as_top 	false 	string 	非置顶：0、置顶：1 	0
         */

        $defaults = array(
            'title' => "",
            "content" => "",
            "save_as_draft" => 0,
            "catalog" => 304044,//工作日志:304043,日常记录:304044,转帖的文章:304045
            "abstracts" => "",
            "tags" => "",
            "classification" => 430381,
            "type" => 1,
            "origin_url" => "",
            "privacy" => 0,
            "deny_comment" => 1,
            "auto_content" => 0,
            "as_top" => 0,
            "access_token" => $this->_get_access_token()
        );

        $args = wp_parse_args($args, $defaults);
        $url = $this->api_site . '/action/openapi/blog_pub';
        $response = wp_remote_post($url, array('body' => $args,'timeout'=>10));

        return $this->_check_api_error($response);

    }

    public function add_meta_boxes(){
        //加入一个metabox
        $sync_data = $this->_get_syn_data();
        $sync_info = "";
        if($sync_data){
            $sync_info = sprintf("<span style='font-weight: normal;font-size: 0.8em'>  上次同步于: %s , 状态: %s </span>" , date_i18n('Y-m-d H:i:s',$sync_data['timestamp']),$sync_data['error_msg']);
        }
        add_meta_box( "oscpress_meta_box", '<strong>OscPress文章同步</strong>'.$sync_info, array($this,'meta_box_callback')) ;
    }

    // 显示在metabox的内容
    public function meta_box_callback(){

        $response = $this->_get_openapi_user();

        if(is_wp_error($response)){
            $this->_show_error_info($response);
            return;
        }

        $myinfo = json_decode($response['body']);
        $sync_data = $this->_get_syn_data();
        if(!$sync_data){
            $sync_data = array(
                'tweet_enable' => 1,
                'catalog' => 304044,
                "classification" => 430381,
                "type" => 1,
                "origin_url" => "",
                "privacy" => 0,
                "deny_comment" => 0,
                "auto_content" => 0,
                "as_top" => 0,

            );
        }
        if( !$sync_data || $sync_data['error_msg'] != 'ok' ){
            $if_sync = true;
        }else{
            $if_sync = false;
        }

?>
        <style>
            .oscpress_options{
                margin: 10px;
            }
            .oscpress_options strong,.oscpress_options label,.oscpress_options select{
                display: inline-block;
                margin-right: 5px;
            }
            .oscpress_options strong {
                font-weight: 200;
            }
        </style>
        <div class="oscpress_options">
            <strong>我的OSC博客:</strong>
            <a href="<?php echo $myinfo->url;?>" target="_blank"><?php echo $myinfo->url;?></a>
        </div>

        <div class="oscpress_options">
            <strong>是否同步这篇文章:</strong>
            <label><input type="radio" name="oscpress_syn_enable" value="1" <?php checked($if_sync,true)?>/>是</label>
            <label><input type="radio" name="oscpress_syn_enable" value="0" <?php checked($if_sync,false)?> />否</label>
        </div>
        <div class="oscpress_options">
            <strong>是否同步后发动弹:</strong>
            <label>
                <input type="radio" name="oscpress_syn[tweet_enable]" value="1" <?php checked($sync_data['tweet_enable'],1)?> />
                是</label>
            <label><input type="radio" name="oscpress_syn[tweet_enable]" value="0"  <?php checked($sync_data['tweet_enable'],0)?>/>否</label>
        </div>
        <div class="oscpress_options">
            <strong>分类:</strong>
            <label><input type="radio" name="oscpress_syn[catalog]" value="304043"  <?php checked($sync_data['catalog'],304043)?> />工作日志</label>
            <label><input type="radio" name="oscpress_syn[catalog]" value="304044" <?php checked($sync_data['catalog'],304044)?> />日常记录</label>
            <label><input type="radio" name="oscpress_syn[catalog]" value="304045" <?php checked($sync_data['catalog'],304045)?> />转帖的文章</label>
        </div>

        <div class="oscpress_options">
            <strong>系统分类:</strong>
            <select class="select_box" name="oscpress_syn[classification]" id="blogcatalogselect">
                <option value="428602" <?php selected($sync_data['classification'],428602)?> ref="blog-classification" >移动开发</option>
                <option value="428612"  <?php selected($sync_data['classification'],428612)?>  ref="blog-classification">前端开发</option>
                <option value="428640" <?php selected($sync_data['classification'],428640)?> ref="blog-classification">服务端开发/管理</option>
                <option value="429511"   <?php selected($sync_data['classification'],429511)?> ref="blog-classification">游戏开发</option>
                <option value="428609"  <?php selected($sync_data['classification'],428609)?> ref="blog-classification">编程语言</option>
                <option value="428610"  <?php selected($sync_data['classification'],428610)?> ref="blog-classification">数据库</option>
                <option value="428611"  <?php selected($sync_data['classification'],428611)?> ref="blog-classification">企业开发</option>
                <option value="428647"  <?php selected($sync_data['classification'],428647)?>  ref="blog-classification">图像/多媒体</option>
                <option value="428613"  <?php selected($sync_data['classification'],428613)?>  ref="blog-classification">系统运维</option>
                <option value="428638"  <?php selected($sync_data['classification'],428638)?> ref="blog-classification">软件工程</option>
                <option value="428639"   <?php selected($sync_data['classification'],428639)?> ref="blog-classification">云计算</option>
                <option value="430884"  <?php selected($sync_data['classification'],430884)?> ref="blog-classification">开源硬件</option>
                <option value="430381"  <?php selected($sync_data['classification'],430381)?> ref="blog-classification" >其他类型</option>
            </select>
        </div>
        <div class="oscpress_options">
            <strong>文章类型:</strong>
            <label><input type="radio" name="oscpress_syn[type]" value="1" <?php checked($sync_data['type'],1)?> />原创</label>
            <label><input type="radio" name="oscpress_syn[type]" value="4" <?php checked($sync_data['type'],4)?> />转帖</label>
        </div>
        <div class="oscpress_options">
            <strong>原文链接:</strong>
            <label><input type="text" size=80 name="oscpress_syn[origin_url]" value="<?php echo $sync_data['origin_url'];?>" placeholder="原文链接,可留空"></label>

        </div>
        <div class="oscpress_options">
            <strong>是否对所有人可见:</strong>
            <label><input type="radio" name="oscpress_syn[privacy]" value="0" <?php checked($sync_data['privacy'],0)?>>可见</label>
            <label><input type="radio" name="oscpress_syn[privacy]" value="1" <?php checked($sync_data['privacy'],1)?> >私密</label>
        </div>
        <div class="oscpress_options">
            <strong>是否允许评论:</strong>
            <label><input type="radio" name="oscpress_syn[deny_comment]" value="0" <?php checked($sync_data['deny_comment'],0)?> />允许</label>
            <label><input type="radio" name="oscpress_syn[deny_comment]" value="1"  <?php checked($sync_data['deny_comment'],1)?> />禁止</label>
        </div>
        <div class="oscpress_options">
            <strong>是否自动生成目录:</strong>
            <label><input type="radio" name="oscpress_syn[auto_content]" value="0" <?php checked($sync_data['auto_content'],0)?>>不生成目录</label>
            <label><input type="radio" name="oscpress_syn[auto_content]" value="1" <?php checked($sync_data['auto_content'],1)?>>生成目录</label>
        </div>
        <div class="oscpress_options">
            <strong>是否在博客列表置顶:</strong>
            <label><input type="radio" name="oscpress_syn[as_top]" value="0" <?php checked($sync_data['as_top'],0)?>>不置顶</label>
            <label><input type="radio" name="oscpress_syn[as_top]" value="1" <?php checked($sync_data['as_top'],1)?>>置顶</label>
        </div>

        <?php
    }
    //  发布一条动弹
    protected function _send_tweet($content,$img_path = null)
    {
        $url = $this->api_site . '/action/openapi/tweet_pub';

        $args = array(
            'access_token' => $this->_get_access_token(),
            'msg' => $content,
        );
        $response = wp_remote_post($url, array('body' => $args,'timeout'=>10));
        return  $this->_check_api_error($response);
    }


    // 获取指定文章同步选项
    protected function _get_syn_data( $post_id = null ) {
        $post = get_post($post_id);
        return get_post_meta($post->ID,"_oscpress_syn",true);
    }

    // 增加对osc api 返回的错误处理
    protected function _check_api_error( $response ) {

        if( !is_wp_error($response) && $response['response']['code'] != 200 ) {
            $error_obj = json_decode($response['body']);
            return new WP_Error($error_obj->error,$error_obj->error_description);
        }
        return $response;
    }
    // 显示错误信息
    protected function _show_error_info( $error ) {

        echo  $error->get_error_message();
        if($error->get_error_code() == 'invalid_token'){
            printf("&nbsp;&nbsp;<em><a href='%s'>点击重新授权</a></em>",$this->_generate_authorize_url());
        }

    }

    protected function _get_thumb_url( $post_id = null ) {

        $post = get_post($post_id);
        $thumb = "http://static.oschina.net/uploads/user/286/572975_100.jpg";
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        if($thumbnail_id){
            $thumb = wp_get_attachment_image_src($thumbnail_id, 'thumbnail');
            $thumb = $thumb[0];

        }else{
            //使用授权用户头像

            $response = $this->_get_openapi_user();
            if(!is_wp_error($response)) {
                $info_obj = json_decode($response['body']);
                $thumb = $info_obj->avatar;
            }
        }

        return $thumb;
    }


    /**
     * @param 发送图片动弹
     * @param null $img_path
     * @param bool|false $debug
     * @return bool
     */
    protected function _send_img_tweet($content,$img_path = null,$debug=false)
    {
        $url = $this->api_site . '/action/openapi/tweet_pub';

        $args = array(
            'access_token' => $this->_get_access_token(),
            'msg' => $content,
        );
        if(!is_null($img_path)) {
            if (class_exists('CURLFile')) {
                $args['img'] =  new CURLFile($img_path);
            } else {
                $args['img'] = '@' . $img_path;
            }
        }
        // 这里使用wp_remote_post上传图片时有问题,直接使用curl处理

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_POSTFIELDS,$args);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);

        $response = curl_exec($ch);
        $debug && var_dump($response);
        $result = !curl_error($ch);
        @curl_close($ch);

        return $result;

    }
}