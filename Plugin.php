<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 按评论数输出读者头像排行，支持Gravatar本地缓存
 * 
 * @package Avatars
 * @author 羽中
 * @version 1.2.2
 * @dependence 13.12.12-*
 * @link http://www.jzwalk.com/archives/net/avatars-for-typecho
 */

class Avatars_Plugin implements Typecho_Plugin_Interface
{
	/**
	 * 激活插件方法,如果激活失败,直接抛出异常
	 * 
	 * @access public
	 * @return void
	 * @throws Typecho_Plugin_Exception
	 */
	public static function activate()
	{
		Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('Avatars_Plugin','listwall');
		Typecho_Plugin::factory('Widget_Abstract_Comments')->gravatar = array('Avatars_Plugin', 'avatars');
	}

	/**
	 * 禁用插件方法,如果禁用失败,直接抛出异常
	 * 
	 * @static
	 * @access public
	 * @return void
	 * @throws Typecho_Plugin_Exception
	 */
	public static function deactivate()	{}

	/**
	 * 获取插件配置面板
	 * 
	 * @access public
	 * @param Typecho_Widget_Helper_Form $form 配置面板
	 * @return void
	 */
	public static function config(Typecho_Widget_Helper_Form $form)
	{
		if (isset($_GET['action']) && $_GET['action'] == 'deletefile')
		self::deletefile();

		echo '<div style="color:#999;font-size:0.92857em;font-weight:bold;word-break:break-all;"><p>
		'._t('在模版适当位置插入代码%s或在编辑器内写入<br/>%s发布均可显示读者墙. 橙色部分为自定义标签名(如span/div/p)和class名. ','<span style="color:#467B96;font-weight:bold">&lt;?php Avatars_Plugin::output("<span style="color:#E47E00;">li</span>","<span style="color:#E47E00;">mostactive</span>"); ?&gt;</span>','<span style="color:#467B96;font-weight:bold">[AVATARS|<span style="color:#E47E00;">li</span>|<span style="color:#E47E00;">mostactive</span>]</span>').'</p></div>';

		$avcache = new Typecho_Widget_Helper_Form_Element_Radio('avcache',
			array('false'=>_t('否'),'true'=>_t('是')),'false',_t('在本地缓存头像'),_t('评论区与读者墙头像均可缓存在cache目录下，每15天自动更新'));
		$form->addInput($avcache);

		$submit = new Typecho_Widget_Helper_Form_Element_Submit();
		$submit->value(_t('清空缓存'));
		$submit->setAttribute('style','position:relative;');
		$submit->input->setAttribute('style','position:absolute;bottom:37px;left:110px;');
		$submit->input->setAttribute('class','btn btn-s btn-warn btn-operate');
		$submit->input->setAttribute('formaction',Typecho_Common::url('/admin/options-plugin.php?config=Avatars&action=deletefile',Helper::options()->siteUrl));
		$form->addItem($submit);

		$avsize = new Typecho_Widget_Helper_Form_Element_Text('avsize',
			NULL,'32',_t('读者墙头像尺寸'),_t('设置读者墙显示的gravatar头像大小(*px*)'));
		$avsize->input->setAttribute('class','w-10');
		$form->addInput($avsize->addRule('isInteger',_t('请填入一个数字')));

		$avdefault = new Typecho_Widget_Helper_Form_Element_Text('avdefault',
			NULL,'',_t('读者墙缺省头像'),_t('设置没有gravatar头像的读者显示图片url(注意与上一项尺寸一致)'));
		$avdefault->input->setAttribute('style','width:550px;');
		$form->addInput($avdefault->addRule('url',_t('请填入一个url地址')));

		$listnumber = new Typecho_Widget_Helper_Form_Element_Text('listnumber',
			NULL,'10',_t('读者墙头像数目'),_t('设置最多显示多少个评论者头像'));
		$listnumber->input->setAttribute('class','w-10');
		$form->addInput($listnumber->addRule('isInteger',_t('请填入一个数字')));

		$since = new Typecho_Widget_Helper_Form_Element_Text('since',
			NULL,'30',_t('读者墙收录时间'),_t('设置显示多少*天*以内的评论者头像'));
		$since->input->setAttribute('class','w-10');
		$form->addInput($since->addRule('isInteger',_t('请填入一个数字')));

		$altword = new Typecho_Widget_Helper_Form_Element_Text('altword',
			NULL,'条评论',_t('读者墙提示文字'),_t('设置评论者头像上的评论数提示后缀'));
		$altword->input->setAttribute('class','mini');
		$form->addInput($altword);
	}

	/**
	 * 个人用户的配置面板
	 * 
	 * @access public
	 * @param Typecho_Widget_Helper_Form $form
	 * @return void
	 */
	public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

	/**
	 * 评论区头像输出
	 *
	 * @access public
	 * @param integer $size 头像尺寸
	 * @param string $default 默认输出头像
	 * @return void
	 */
	public function avatars($size,$rating,$default,$comments)
	{
		$settings = Helper::options()->plugin('Avatars');
		$default = (!empty($default)) ? $default : 'http://gravatar.duoshuo.com/avatar/?s='.$size.'&amp;d=';

		if (!empty($comments->mail)) {
			$mailhash = md5(strtolower($comments->mail));
		}

		//默认读取多说源
		$url = 'http://gravatar.duoshuo.com/avatar/';
		if (!empty($comments->mail)) {
			$url .= $mailhash;
		}
		$url .= '?s='.$size;
		$url .= '&amp;r='.$rating;
		$url .= '&amp;d='.$default;

		//调用缓存地址判断
		$imgurl = ($settings->avcache == 'true') ? self::cache($size,$default,$url,$mailhash) : $url;
		echo '<img class="avatar" src="'.$imgurl.'" alt="'.$comments->author.'"/>';
	}

	/**
	 * 读者墙标签解析
	 * 
	 * @access public
	 * @param string $content
	 * @return string
	 */
	public static function listwall($content,$widget,$lastResult)
	{
		$content = empty($lastResult) ? $content : $lastResult;

		if ($widget instanceof Widget_Archive) {
			return preg_replace_callback('/\[AVATARS(\w*[^>]*)\]/i',array('Avatars_Plugin','callback'),$content);
		} else {
			return $content;
		}

	}

	/**
	 * 标签参数回调
	 * 
	 * @access public
	 * @param array $matches
	 * @return string
	 */
	public static function callback($matches)
	{
		$listtag = 'li';
		$class = 'mostactive';
		if (!empty($matches[1])) {
			if (preg_match('/\|([\w-]*)\|([\w-]*)/i',$matches[1],$out)) {
				$listtag = trim($out[1]) == '' ? $listtag : trim($out[1]);
				$class = trim($out[2]) == '' ? $class : trim($out[2]);
			}
		}
		return self::output($listtag,$class);
	}

	/**
	 * 读者墙实例输出
	 * 
	 * @access public
	 * @param string $listtag 标签名称
	 * @param string $class class名称
	 * @return void
	 */
	public static function output($listtag = 'li',$class = 'mostactive')
	{
		$options = Helper::options();
		$settings = $options->plugin('Avatars');
		$mostactive = '';
		$avsize = $settings->avsize;

		//兼容缓存的默认头像
		$avdefault = (!empty($settings->avdefault)) ? $settings->avdefault : 'http://gravatar.duoshuo.com/avatar/?s='.$avsize.'&amp;d=';

		//同步系统nofollow设置
		$nofollow = ($options->commentsUrlNofollow) ? 'rel="external nofollow"' : '';

		//收录时间计算
		$expire = $options->gmtTime+$options->timezone-$settings->since*24*3600;

		$db = Typecho_Db::get();
		$select = $db->select(array('COUNT(author)'=>'cnt'),'author','url','mail')->from('table.comments')
			->where('status = ?','approved')
			->where('authorId = ?','0')
			->where('type = ?','comment')
			->where('created > ?',$expire)
			->limit($settings->listnumber)
			->group('author')
			->order('cnt',Typecho_Db::SORT_DESC);
		$counts = $db->fetchAll($select);

		foreach ($counts as $count) {
			//url未填写链接静默
			$visurl = (!empty($count['url'])) ? $count['url'] : '###';
			$avhash = md5($count['mail']);

			//同步系统头像评级
			$avurl = 'http://gravatar.duoshuo.com/avatar/'.$avhash.'?s='.$avsize.'&amp;r='.$options->commentsAvatarRating.'&amp;d='.$avdefault;

			//调用缓存地址判断
			$imgurl = ($settings->avcache == 'true') ? self::cache($avsize,$avdefault,$avurl,$avhash) : $avurl;

			$mostactive .= '<'.$listtag.''.(empty($class) ? '' : ' class="'.$class.'"').'>'.'<a href="'.$visurl.'"'.$nofollow.'title="'.$count['author'].' - '.$count['cnt'].$settings->altword.'"><img src="'.$imgurl.'" alt="'.$count['author'].' - '.$count['cnt'].$settings->altword.'" class="avatar" /></a></'.$listtag.'>';
		}

		echo $mostactive;
	}

	/**
	 * 简易缓存生成
	 * 
	 * @access public
	 * @param integer $size 头像尺寸
	 * @param string $default 默认头像
	 * @param string $image 用户头像
	 * @param string $mailhash 邮箱哈希
	 * @return string
	 */
	private static function cache($size,$default,$image,$mailhash)
	{
		$options = Helper::options();
		$settings = $options->plugin('Avatars');

		//缓存目录绝对路径
		$path = __TYPECHO_ROOT_DIR__.__TYPECHO_PLUGIN_DIR__.'/Avatars/cache/';
		if (!is_dir($path)) {
			if (!self::makedir($path)) {
				return false;
			}
		}
		$defaultdir = $path.'default'.$size;
		$sampledir = $path.'set'.$size;
		$cachedir = $path.$mailhash.$size;

		//缓存默认时限15日
		$cachetime = 14*24*3600;

		if (!is_file($defaultdir))
			copy($default,$defaultdir);
		if (!is_file($sampledir))
			copy('http://gravatar.duoshuo.com/avatar/?s='.$settings->avsize.'&amp;d=',$sampledir);

		//不存在或过期则生成
		if (!is_file($cachedir) || (time()-filemtime($cachedir))>$cachetime)
			copy($image,$cachedir);

		//自定义默认头像覆盖
		if (filesize($cachedir) == filesize($sampledir))
			copy($defaultdir,$cachedir);

		return $options->pluginUrl.'/Avatars/cache/'.$mailhash.$size;
	}

	/**
	 * 简易缓存清空
	 *
	 * @access private
	 * @return void
	 */
	private function deletefile()
	{
		$path = __TYPECHO_ROOT_DIR__ .'/usr/plugins/Avatars/cache/';

		foreach (glob($path.'*') as $filename) {
			unlink($filename);
		}

		Typecho_Widget::widget('Widget_Notice')->set(_t('本地头像缓存已清空!'),NULL,'success');

		Typecho_Response::getInstance()->goBack();
	}

	/**
	 * 本地目录创建
	 *
	 * @access private
	 * @param string $path 路径
	 * @return boolean
	 */
	private static function makedir($path)
	{
		if (!@mkdir($path,0777,true)) {
			return false;
		}
		$stat = @stat($path);
		$perms = $stat['mode']&0007777;
		@chmod($path,$perms);
		return true;
	}

}