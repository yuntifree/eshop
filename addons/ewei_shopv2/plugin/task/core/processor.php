<?php
if (!(defined('IN_IA'))) 
{
	exit('Access Denied');
}
require IA_ROOT . '/addons/ewei_shopv2/defines.php';
require EWEI_SHOPV2_INC . 'plugin_processor.php';
class TaskProcessor extends PluginProcessor 
{
	public function __construct() 
	{
		parent::__construct('task');
	}
	public function respond($obj = NULL) 
	{
		global $_W;
		$message = $obj->message;
		$msgtype = strtolower($message['msgtype']);
		$event = strtolower($message['event']);
		$obj->member = $this->model->checkMember($message['from']);
		if (($msgtype == 'text') || ($event == 'click')) 
		{
			return $this->responseText($obj);
		}
		if ($msgtype == 'event') 
		{
			if ($event == 'scan') 
			{
				return $this->responseScan($obj);
			}
			if ($event == 'subscribe') 
			{
				return $this->responseSubscribe($obj);
			}
		}
	}
	private function responseText($obj) 
	{
		global $_W;
		$timeout = 4;
		load()->func('communication');
		$url = mobileUrl('task/build', array('timestamp' => TIMESTAMP), true);
		ihttp_request($url, array('openid' => $obj->message['from'], 'content' => urlencode($obj->message['content'])), array(), $timeout);
		return $this->responseEmpty();
	}
	private function responseEmpty() 
	{
		ob_clean();
		ob_start();
		echo '';
		ob_flush();
		ob_end_flush();
		exit(0);
	}
	private function responseDefault($obj) 
	{
		global $_W;
		return $obj->respText('感谢您的关注!');
	}
	private function responseScan($obj) 
	{
		global $_W;
		$openid = $obj->message['from'];
		$sceneid = $obj->message['eventkey'];
		$ticket = $obj->message['ticket'];
		if (empty($ticket)) 
		{
			return $this->responseDefault($obj);
		}
		$qr = $this->model->getQRByTicket($ticket);
		if (empty($qr)) 
		{
			return $this->responseDefault($obj);
		}
		$poster = pdo_fetch('select * from ' . tablename('ewei_shop_task_poster') . ' where id=:id and uniacid=:uniacid limit 1', array(':id' => $qr['posterid'], ':uniacid' => $_W['uniacid']));
		if (empty($poster)) 
		{
			return $this->responseDefault($obj);
		}
		$member_info = $obj->member;
		$qrmember = m('member')->getMember($qr['openid']);
		$join_info = pdo_fetch('select `join_id`,`needcount`,`completecount`,`failtime`,`task_type`,`reward_data`,`is_reward` from ' . tablename('ewei_shop_task_join') . ' where uniacid=:uniacid and join_user=:join_user and task_id=:task_id and task_type=:task_type and failtime>' . time() . ' order by addtime DESC limit 1', array(':uniacid' => $_W['uniacid'], ':join_user' => $qrmember['openid'], ':task_id' => $poster['id'], ':task_type' => $poster['poster_type']));
		if ($openid == $qr['openid']) 
		{
			$default_text = pdo_fetchcolumn('SELECT `data` FROM ' . tablename('ewei_shop_task_default') . ' WHERE uniacid=:uniacid limit 1', array(':uniacid' => $_W['uniacid']));
			if (!(empty($default_text))) 
			{
				$default_text = unserialize($default_text);
				if (!(empty($default_text['self']))) 
				{
					$poster['okdays'] = $join_info['failtime'];
					$poster['completecount'] = $join_info['completecount'];
					foreach ($default_text['self'] as $key => $val ) 
					{
						$default_text['self'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 1);
					}
					return m('message')->sendTplNotice($openid, $default_text['templateid'], $default_text['self'], '');
				}
			}
			return m('message')->sendCustomNotice($openid, '扫描自己的海报是不会增加人气值的,快快把你的海报发送给你的小伙伴吧~');
		}
		load()->func('logging');
		if ($member_info['isnew']) 
		{
			if (!(empty($join_info))) 
			{
				if ($join_info['task_type'] == 1) 
				{
					$this->model->reward($member_info, $poster, $join_info, $qr, $openid, $qrmember);
				}
				else if ($join_info['task_type'] == 2) 
				{
					logging_run('saomiao 2leixing');
					$this->model->rankreward($member_info, $poster, $join_info, $qr, $openid, $qrmember);
				}
				$this->commission($poster, $member_info, $qrmember);
			}
		}
		else 
		{
			$params = array(':uniacid' => $_W['uniacid'], ':task_user' => $qr['openid'], ':joiner_id' => $openid, ':join_id' => $join_info['join_id']);
			$scan_count = pdo_fetchcolumn('SELECT COUNT(*) FROM ' . tablename('ewei_shop_task_joiner') . ' where uniacid=:uniacid and task_user=:task_user and joiner_id=:joiner_id and join_id=:join_id ', $params);
			if (!(empty($join_info)) && $scan_count) 
			{
				$default_text = pdo_fetchcolumn('SELECT `data` FROM ' . tablename('ewei_shop_task_default') . ' WHERE uniacid=:uniacid limit 1', array(':uniacid' => $_W['uniacid']));
				if (!(empty($default_text))) 
				{
					$default_text = unserialize($default_text);
					if (!(empty($default_text['fail']))) 
					{
						$poster['okdays'] = $join_info['failtime'];
						$poster['completecount'] = $join_info['completecount'];
						foreach ($default_text['fail'] as $key => $val ) 
						{
							$default_text['fail'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 1);
						}
						m('message')->sendTplNotice($openid, $default_text['templateid'], $default_text['fail'], '');
					}
				}
				else 
				{
					m('message')->sendCustomNotice($openid, '您之前已经参加过此任务');
				}
			}
			else if (empty($join_info)) 
			{
				$default_text = pdo_fetchcolumn('SELECT `data` FROM ' . tablename('ewei_shop_task_default') . ' WHERE uniacid=:uniacid limit 1', array(':uniacid' => $_W['uniacid']));
				if (!(empty($default_text))) 
				{
					$default_text = unserialize($default_text);
					if (!(empty($default_text['fail']))) 
					{
						$poster['okdays'] = $join_info['failtime'];
						$poster['completecount'] = $join_info['completecount'];
						foreach ($default_text['fail'] as $key => $val ) 
						{
							$default_text['fail'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 1);
						}
						m('message')->sendTplNotice($openid, $default_text['templateid'], $default_text['fail'], '');
					}
				}
				else 
				{
					m('message')->sendCustomNotice($openid, '此任务已过期或不存在');
				}
			}
			else 
			{
				$default_text = pdo_fetchcolumn('SELECT `data` FROM ' . tablename('ewei_shop_task_default') . ' WHERE uniacid=:uniacid limit 1', array(':uniacid' => $_W['uniacid']));
				if (!(empty($default_text))) 
				{
					$default_text = unserialize($default_text);
					if (!(empty($default_text['fail']))) 
					{
						$poster['okdays'] = $join_info['failtime'];
						$poster['completecount'] = $join_info['completecount'];
						foreach ($default_text['fail'] as $key => $val ) 
						{
							$default_text['fail'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 1);
						}
						m('message')->sendTplNotice($openid, $default_text['templateid'], $default_text['fail'], '');
					}
				}
				else 
				{
					m('message')->sendCustomNotice($openid, '此任务只对新用户开放');
				}
			}
		}
		$url = trim($poster['respurl']);
		if (empty($url)) 
		{
			if (($qrmember['isagent'] == 1) && ($qrmember['status'] == 1)) 
			{
				$url = mobileUrl('commission/myshop', array('mid' => $qrmember['id']));
			}
			else 
			{
				$url = mobileUrl('', array('mid' => $qrmember['id']));
			}
		}
		if ($poster['resptype'] == '0') 
		{
			if (!(empty($poster['resptitle']))) 
			{
				$news = array( array('title' => $poster['resptitle'], 'description' => $poster['respdesc'], 'picurl' => tomedia($poster['respthumb']), 'url' => $url) );
				return $obj->respNews($news);
			}
		}
		if ($poster['resptype'] == '1') 
		{
			if (!(empty($poster['resptext']))) 
			{
				return $obj->respText($poster['resptext']);
			}
		}
		return $this->responseEmpty();
	}
	private function responseSubscribe($obj) 
	{
		global $_W;
		$openid = $obj->message['from'];
		$ticket = $obj->message['ticket'];
		$member_info = $obj->member;
		if (empty($ticket)) 
		{
			return $this->responseDefault($obj);
		}
		$qr = $this->model->getQRByTicket($ticket);
		if (empty($qr)) 
		{
			return $this->responseDefault($obj);
		}
		$poster = pdo_fetch('select * from ' . tablename('ewei_shop_task_poster') . ' where id=:id and uniacid=:uniacid limit 1', array(':id' => $qr['posterid'], ':uniacid' => $_W['uniacid']));
		if (empty($poster)) 
		{
			return $this->responseDefault($obj);
		}
		$qrmember = m('member')->getMember($qr['openid']);
		$join_info = pdo_fetch('select `join_id`,`needcount`,`completecount`,`failtime`,`task_type`,`reward_data`,`is_reward` from ' . tablename('ewei_shop_task_join') . ' where uniacid=:uniacid and join_user=:join_user and task_id=:task_id and task_type=:task_type and failtime>' . time() . ' order by addtime DESC limit 1', array(':uniacid' => $_W['uniacid'], ':join_user' => $qrmember['openid'], ':task_id' => $poster['id'], ':task_type' => $poster['poster_type']));
		if ($openid == $qr['openid']) 
		{
			$default_text = pdo_fetchcolumn('SELECT `data` FROM ' . tablename('ewei_shop_task_default') . ' WHERE uniacid=:uniacid limit 1', array(':uniacid' => $_W['uniacid']));
			if (!(empty($default_text))) 
			{
				$default_text = unserialize($default_text);
				if (!(empty($default_text['self']))) 
				{
					$poster['okdays'] = $join_info['failtime'];
					$poster['completecount'] = $join_info['completecount'];
					foreach ($default_text['self'] as $key => $val ) 
					{
						$default_text['self'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 1);
					}
					return m('message')->sendTplNotice($openid, $default_text['templateid'], $default_text['self'], '');
				}
			}
			return m('message')->sendCustomNotice($openid, '扫描自己的海报是不会增加人气值的,快快把你的海报发送给你的小伙伴吧~');
		}
		if ($member_info['isnew']) 
		{
			if (!(empty($join_info))) 
			{
				if ($join_info['task_type'] == 1) 
				{
					$this->model->reward($member_info, $poster, $join_info, $qr, $openid, $qrmember);
				}
				else if ($join_info['task_type'] == 2) 
				{
					$this->model->rankreward($member_info, $poster, $join_info, $qr, $openid, $qrmember);
				}
				$this->commission($poster, $member_info, $qrmember);
			}
		}
		else 
		{
			$params = array(':uniacid' => $_W['uniacid'], ':task_user' => $qr['openid'], ':joiner_id' => $openid, ':join_id' => $join_info['join_id']);
			$scan_count = pdo_fetchcolumn('SELECT COUNT(*) FROM ' . tablename('ewei_shop_task_joiner') . ' where uniacid=:uniacid and task_user=:task_user and joiner_id=:joiner_id and join_id=:join_id ', $params);
			if (!(empty($join_info)) && $scan_count) 
			{
				$default_text = pdo_fetchcolumn('SELECT `data` FROM ' . tablename('ewei_shop_task_default') . ' WHERE uniacid=:uniacid limit 1', array(':uniacid' => $_W['uniacid']));
				if (!(empty($default_text))) 
				{
					$default_text = unserialize($default_text);
					if (!(empty($default_text['fail'])) && !(empty($default_text['templateid']))) 
					{
						$poster['okdays'] = $join_info['failtime'];
						$poster['completecount'] = $join_info['completecount'];
						foreach ($default_text['fail'] as $key => $val ) 
						{
							$default_text['fail'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 1);
						}
						m('message')->sendTplNotice($openid, $default_text['templateid'], $default_text['fail'], '');
					}
					else 
					{
						m('message')->sendCustomNotice($openid, '您之前已经参加过此任务');
					}
				}
				else 
				{
					m('message')->sendCustomNotice($openid, '您之前已经参加过此任务');
				}
			}
			else if (empty($join_info)) 
			{
				$default_text = pdo_fetchcolumn('SELECT `data` FROM ' . tablename('ewei_shop_task_default') . ' WHERE uniacid=:uniacid limit 1', array(':uniacid' => $_W['uniacid']));
				if (!(empty($default_text))) 
				{
					$default_text = unserialize($default_text);
					if (!(empty($default_text['fail'])) && !(empty($default_text['templateid']))) 
					{
						$poster['okdays'] = $join_info['failtime'];
						$poster['completecount'] = $join_info['completecount'];
						foreach ($default_text['fail'] as $key => $val ) 
						{
							$default_text['fail'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 1);
						}
						m('message')->sendTplNotice($openid, $default_text['templateid'], $default_text['fail'], '');
					}
					else 
					{
						m('message')->sendCustomNotice($openid, '此任务已过期或不存在');
					}
				}
				else 
				{
					m('message')->sendCustomNotice($openid, '此任务已过期或不存在');
				}
			}
			else 
			{
				$default_text = pdo_fetchcolumn('SELECT `data` FROM ' . tablename('ewei_shop_task_default') . ' WHERE uniacid=:uniacid limit 1', array(':uniacid' => $_W['uniacid']));
				if (!(empty($default_text))) 
				{
					$default_text = unserialize($default_text);
					if (!(empty($default_text['fail'])) && !(empty($default_text['templateid']))) 
					{
						$poster['okdays'] = $join_info['failtime'];
						$poster['completecount'] = $join_info['completecount'];
						foreach ($default_text['fail'] as $key => $val ) 
						{
							$default_text['fail'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 1);
						}
						m('message')->sendTplNotice($openid, $default_text['templateid'], $default_text['fail'], '');
					}
					else 
					{
						m('message')->sendCustomNotice($openid, '此任务只对新用户开放');
					}
				}
				else 
				{
					m('message')->sendCustomNotice($openid, '此任务只对新用户开放');
				}
			}
		}
		$url = trim($poster['respurl']);
		if (empty($url)) 
		{
			if (($qrmember['isagent'] == 1) && ($qrmember['status'] == 1)) 
			{
				$url = mobileUrl('commission/myshop', array('mid' => $qrmember['id']));
			}
			else 
			{
				$url = mobileUrl('', array('mid' => $qrmember['id']));
			}
		}
		if ($poster['resptype'] == '0') 
		{
			if (!(empty($poster['resptitle']))) 
			{
				$news = array( array('title' => $poster['resptitle'], 'description' => $poster['respdesc'], 'picurl' => tomedia($poster['respthumb']), 'url' => $url) );
				return $obj->respNews($news);
			}
		}
		if ($poster['resptype'] == '1') 
		{
			if (!(empty($poster['resptext']))) 
			{
				return $obj->respText($poster['resptext']);
			}
		}
		return $this->responseEmpty();
	}
	private function commission($poster, $member, $qrmember) 
	{
		$time = time();
		$p = p('commission');
		if ($p) 
		{
			$cset = $p->getSet();
			if (!(empty($cset))) 
			{
				if ($member['isagent'] != 1) 
				{
					if (($qrmember['isagent'] == 1) && ($qrmember['status'] == 1)) 
					{
						if (!(empty($poster['bedown']))) 
						{
							if (empty($member['agentid'])) 
							{
								if (empty($member['fixagentid'])) 
								{
									pdo_update('ewei_shop_member', array('agentid' => $qrmember['id'], 'childtime' => $time), array('id' => $member['id']));
									$member['agentid'] = $qrmember['id'];
									$p->sendMessage($qrmember['openid'], array('nickname' => $member['nickname'], 'childtime' => $time), TM_COMMISSION_AGENT_NEW);
									$p->upgradeLevelByAgent($qrmember['id']);
									if (p('globonus')) 
									{
										p('globonus')->upgradeLevelByAgent($qrmember['id']);
									}
									if (p('abonus')) 
									{
										p('abonus')->upgradeLevelByAgent($qrmember['id']);
									}
									if (p('author')) 
									{
										p('author')->upgradeLevelByAgent($qrmember['id']);
									}
								}
							}
							if (!(empty($poster['beagent']))) 
							{
								$become_check = intval($cset['become_check']);
								pdo_update('ewei_shop_member', array('isagent' => 1, 'status' => $become_check, 'agenttime' => $time), array('id' => $member['id']));
								if ($become_check == 1) 
								{
									$p->sendMessage($member['openid'], array('nickname' => $member['nickname'], 'agenttime' => $time), TM_COMMISSION_BECOME);
									$p->upgradeLevelByAgent($qrmember['id']);
									if (p('globonus')) 
									{
										p('globonus')->upgradeLevelByAgent($qrmember['id']);
									}
									if (p('abonus')) 
									{
										p('abonus')->upgradeLevelByAgent($qrmember['id']);
									}
									if (p('author')) 
									{
										p('author')->upgradeLevelByAgent($qrmember['id']);
									}
								}
							}
						}
					}
				}
			}
		}
	}
	private function reward($member_info, $poster, $join_info, $qr, $openid, $qrmember) 
	{
		if (empty($member_info) || empty($poster) || empty($join_info) || empty($openid) || empty($qr)) 
		{
			return false;
		}
		global $_W;
		load()->func('logging');
		$reward_data = unserialize($poster['reward_data']);
		$count = $join_info['completecount'] + 1;
		if (($join_info['needcount'] == $count) && ($join_info['is_reward'] == 0)) 
		{
			$reward = serialize($reward_data['rec']);
			$sub_reward = serialize($reward_data['sub']);
			$reward_log = array('uniacid' => $_W['uniacid'], 'openid' => $qr['openid'], 'from_openid' => $openid, 'join_id' => $join_info['join_id'], 'taskid' => $qr['posterid'], 'task_type' => 1, 'subdata' => $sub_reward, 'recdata' => $reward, 'createtime' => time());
			pdo_update('ewei_shop_task_join', array('completecount' => $count, 'is_reward' => 1, 'reward_data' => $reward), array('uniacid' => $_W['uniacid'], 'join_id' => $join_info['join_id'], 'join_user' => $qr['openid'], 'task_id' => $poster['id'], 'task_type' => 1));
			pdo_insert('ewei_shop_task_log', $reward_log);
			$log_id = pdo_insertid();
			$scaner = array('uniacid' => $_W['uniacid'], 'task_user' => $qr['openid'], 'joiner_id' => $openid, 'task_id' => $qr['posterid'], 'join_id' => $join_info['join_id'], 'task_type' => 1, 'join_status' => 1, 'addtime' => time());
			pdo_insert('ewei_shop_task_joiner', $scaner);
			foreach ($reward_data as $key => $val ) 
			{
				if ($key == 'rec') 
				{
					if (isset($val['credit']) && (0 < $val['credit'])) 
					{
						m('member')->setCredit($qr['openid'], 'credit1', $val['credit'], array(0, '推荐扫码关注积分+' . $val['credit']));
					}
					if (isset($val['money']) && (0 < $val['money']['num'])) 
					{
						$pay = $val['money']['num'];
						if ($val['money']['type'] == 1) 
						{
							$pay *= 100;
						}
						m('finance')->pay($qr['openid'], $val['money']['type'], $pay, '', '任务活动推荐奖励', false);
					}
					if (isset($val['bribery']) && (0 < $val['bribery'])) 
					{
						$setting = uni_setting($_W['uniacid'], array('payment'));
						if (!(is_array($setting['payment']))) 
						{
							return error(1, '没有设定支付参数');
						}
						$sec = m('common')->getSec();
						$sec = iunserializer($sec['sec']);
						$certs = $sec;
						$wechat = $setting['payment']['wechat'];
						$sql = 'SELECT `key`,`secret` FROM ' . tablename('account_wechats') . ' WHERE `uniacid`=:uniacid limit 1';
						$row = pdo_fetch($sql, array(':uniacid' => $_W['uniacid']));
						$tid = rand(1, 1000) . time() . rand(1, 10000);
						$params = array('openid' => $qr['openid'], 'tid' => $tid, 'send_name' => '推荐奖励', 'money' => $val['bribery'], 'wishing' => '推荐奖励', 'act_name' => $poster['title'], 'remark' => '推荐奖励');
						$wechat = array('appid' => $row['key'], 'mchid' => $wechat['mchid'], 'apikey' => $wechat['apikey'], 'certs' => $certs);
						$err = m('common')->sendredpack($params, $wechat);
						if (!(is_error($err))) 
						{
							$reward = unserialize($reward);
							$reward['briberyOrder'] = $tid;
							$reward = serialize($reward);
							$upgrade = array('recdata' => $reward);
							pdo_update('ewei_shop_task_log', $upgrade, array('id' => $log_id));
						}
					}
					if (isset($val['coupon']) && !(empty($val['coupon']))) 
					{
						$cansendreccoupon = false;
						$plugin_coupon = com('coupon');
						unset($val['coupon']['total']);
						foreach ($val['coupon'] as $k => $v ) 
						{
							if ($plugin_coupon) 
							{
								if (!(empty($v['id'])) && (0 < $v['couponnum'])) 
								{
									$reccoupon = $plugin_coupon->getCoupon($v['id']);
									if (!(empty($reccoupon))) 
									{
										$cansendreccoupon = true;
									}
								}
							}
							if ($cansendreccoupon) 
							{
								$plugin_coupon->taskposter($qrmember, $v['id'], $v['couponnum']);
							}
						}
					}
				}
				else if ($key == 'sub') 
				{
					if (0 < $val['credit']) 
					{
						m('member')->setCredit($openid, 'credit1', $val['credit'], array(0, '扫码关注积分+' . $val['credit']));
					}
					if (0 < $val['money']['num']) 
					{
						$pay = $val['money']['num'];
						if ($val['money']['type'] == 1) 
						{
							$pay *= 100;
						}
						$res = m('finance')->pay($openid, $val['money']['type'], $pay, '', '任务活动奖励', false);
						if (is_error($res)) 
						{
							logging_run($res['message']);
						}
					}
					if (isset($val['coupon']) && isset($val['coupon']) && !(empty($val['coupon']))) 
					{
						$cansendreccoupon = false;
						$plugin_coupon = com('coupon');
						unset($val['coupon']['total']);
						foreach ($val['coupon'] as $k => $v ) 
						{
							if ($plugin_coupon) 
							{
								if (!(empty($v['id'])) && (0 < $v['couponnum'])) 
								{
									$reccoupon = $plugin_coupon->getCoupon($v['id']);
									if (!(empty($reccoupon))) 
									{
										$cansendreccoupon = true;
									}
								}
							}
							if ($cansendreccoupon) 
							{
								$plugin_coupon->taskposter($member_info, $v['id'], $v['couponnum']);
							}
						}
					}
				}
			}
			$default_text = pdo_fetchcolumn('SELECT `data` FROM ' . tablename('ewei_shop_task_default') . ' WHERE uniacid=:uniacid limit 1', array(':uniacid' => $_W['uniacid']));
			if (!(empty($default_text))) 
			{
				$default_text = unserialize($default_text);
				if (!(empty($default_text['successscaner']))) 
				{
					$poster['okdays'] = $join_info['failtime'];
					$poster['completecount'] = $join_info['completecount'];
					foreach ($default_text['successscaner'] as $key => $val ) 
					{
						$default_text['successscaner'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 1);
					}
					logging_run($default_text);
					if ($default_text['templateid']) 
					{
						m('message')->sendTplNotice($openid, $default_text['templateid'], $default_text['successscaner'], '');
					}
					else 
					{
						m('message')->sendCustomNotice($openid, '感谢您的关注，恭喜您获得关注奖励');
					}
				}
				else 
				{
					m('message')->sendCustomNotice($openid, '感谢您的关注，恭喜您获得关注奖励');
				}
				if (!(empty($default_text['complete']))) 
				{
					$poster['okdays'] = $join_info['failtime'];
					$poster['completecount'] = $count;
					foreach ($default_text['complete'] as $key => $val ) 
					{
						$default_text['complete'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 2);
					}
					if ($default_text['templateid']) 
					{
						m('message')->sendTplNotice($qrmember['openid'], $default_text['templateid'], $default_text['complete'], mobileUrl('task', array('tabpage' => 'complete'), true));
						return;
					}
					m('message')->sendCustomNotice($qrmember['openid'], '亲爱的' . $qrmember['nickname'] . '恭喜您完成任务获得奖励', mobileUrl('task', array('tabpage' => 'complete'), true));
					return;
				}
				m('message')->sendCustomNotice($qrmember['openid'], '亲爱的' . $qrmember['nickname'] . '恭喜您完成任务获得奖励', mobileUrl('task', array('tabpage' => 'complete'), true));
				return;
			}
			m('message')->sendCustomNotice($openid, '感谢您的关注，恭喜您获得关注奖励');
			m('message')->sendCustomNotice($openid, '亲爱的' . $qrmember['nickname'] . '恭喜您完成任务获得奖励', mobileUrl('task', array('tabpage' => 'complete'), true));
			return;
		}
		$reward = serialize($reward_data['rec']);
		$sub_reward = serialize($reward_data['sub']);
		$reward_log = array('uniacid' => $_W['uniacid'], 'openid' => $qr['openid'], 'from_openid' => $openid, 'join_id' => $join_info['join_id'], 'taskid' => $qr['posterid'], 'task_type' => 1, 'subdata' => $sub_reward, 'createtime' => time());
		pdo_update('ewei_shop_task_join', array('completecount' => $count), array('uniacid' => $_W['uniacid'], 'join_user' => $qr['openid'], 'task_id' => $poster['id'], 'task_type' => 1));
		pdo_insert('ewei_shop_task_log', $reward_log);
		$log_id = pdo_insertid();
		$scaner = array('uniacid' => $_W['uniacid'], 'task_user' => $qr['openid'], 'joiner_id' => $openid, 'task_id' => $qr['posterid'], 'join_id' => $join_info['join_id'], 'task_type' => 1, 'join_status' => 1, 'addtime' => time());
		pdo_insert('ewei_shop_task_joiner', $scaner);
		foreach ($reward_data as $key => $val ) 
		{
			if ($key == 'sub') 
			{
				if (0 < $val['credit']) 
				{
					m('member')->setCredit($openid, 'credit1', $val['credit'], array(0, '扫码关注积分+' . $val['credit']));
				}
				if (0 < $val['money']['num']) 
				{
					$pay = $val['money']['num'];
					if ($val['money']['type'] == 1) 
					{
						$pay *= 100;
					}
					$res = m('finance')->pay($openid, $val['money']['type'], $pay, '', '任务活动奖励', false);
					logging_run('submoney' . json_encode($res));
					if (is_error($res)) 
					{
						logging_run('submoney' . $res['message']);
					}
				}
				if (0 < $val['bribery']) 
				{
					$setting = uni_setting($_W['uniacid'], array('payment'));
					if (!(is_array($setting['payment']))) 
					{
						return error(1, '没有设定支付参数');
					}
					$sec = m('common')->getSec();
					$sec = iunserializer($sec['sec']);
					$certs = $sec;
					$wechat = $setting['payment']['wechat'];
					$sql = 'SELECT `key`,`secret` FROM ' . tablename('account_wechats') . ' WHERE `uniacid`=:uniacid limit 1';
					$row = pdo_fetch($sql, array(':uniacid' => $_W['uniacid']));
					$tid = rand(1, 1000) . time() . rand(1, 10000);
					$params = array('openid' => $openid, 'tid' => $tid, 'send_name' => '推荐奖励', 'money' => $val['bribery'], 'wishing' => '推荐奖励', 'act_name' => $poster['title'], 'remark' => '推荐奖励');
					$wechat = array('appid' => $row['key'], 'mchid' => $wechat['mchid'], 'apikey' => $wechat['apikey'], 'certs' => $certs);
					$err = m('common')->sendredpack($params, $wechat);
					logging_run('bribery' . json_encode($err));
					if (!(is_error($err))) 
					{
						$sub_reward = unserialize($sub_reward);
						$sub_reward['briberyOrder'] = $tid;
						$sub_reward = serialize($sub_reward);
						$upgrade = array('subdata' => $sub_reward);
						pdo_update('ewei_shop_task_log', $upgrade, array('id' => $log_id));
					}
					else 
					{
						logging_run('bribery' . $err['message']);
					}
				}
				if (isset($val['coupon']) && !(empty($val['coupon']))) 
				{
					$cansendreccoupon = false;
					$plugin_coupon = com('coupon');
					unset($val['coupon']['total']);
					foreach ($val['coupon'] as $k => $v ) 
					{
						if ($plugin_coupon) 
						{
							$cansendreccoupon = false;
							if (!(empty($v['id'])) && (0 < $v['couponnum'])) 
							{
								$reccoupon = $plugin_coupon->getCoupon($v['id']);
								if (!(empty($reccoupon))) 
								{
									$cansendreccoupon = true;
								}
							}
						}
						if ($cansendreccoupon) 
						{
							$plugin_coupon->taskposter($member_info, $v['id'], $v['couponnum']);
						}
					}
				}
			}
		}
		$default_text = pdo_fetchcolumn('SELECT `data` FROM ' . tablename('ewei_shop_task_default') . ' WHERE uniacid=:uniacid limit 1', array(':uniacid' => $_W['uniacid']));
		if (!(empty($default_text))) 
		{
			$default_text = unserialize($default_text);
			if (!(empty($default_text['successscaner']))) 
			{
				$poster['okdays'] = $join_info['failtime'];
				$poster['completecount'] = $join_info['completecount'];
				foreach ($default_text['successscaner'] as $key => $val ) 
				{
					$default_text['successscaner'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 1);
				}
				if ($default_text['templateid']) 
				{
					m('message')->sendTplNotice($openid, $default_text['templateid'], $default_text['successscaner'], '');
				}
				else 
				{
					m('message')->sendCustomNotice($openid, '感谢您的关注，恭喜您获得关注奖励');
				}
			}
			else 
			{
				m('message')->sendCustomNotice($openid, '感谢您的关注，恭喜您获得关注奖励');
			}
			if (!(empty($default_text['successtasker']))) 
			{
				$poster['okdays'] = $join_info['failtime'];
				$poster['completecount'] = $count;
				foreach ($default_text['successtasker'] as $key => $val ) 
				{
					$default_text['successtasker'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 2);
				}
				if ($default_text['templateid']) 
				{
					m('message')->sendTplNotice($qrmember['openid'], $default_text['templateid'], $default_text['successtasker'], mobileUrl('task', array('tabpage' => 'runninga'), true));
					return;
				}
				m('message')->sendCustomNotice($qrmember['openid'], '亲爱的' . $qrmember['nickname'] . '您的海报被' . $member_info['nickname'] . '关注,增加了1点人气值', mobileUrl('task', array('tabpage' => 'runninga'), true));
				return;
			}
			m('message')->sendCustomNotice($qrmember['openid'], '亲爱的' . $qrmember['nickname'] . '您的海报被' . $member_info['nickname'] . '关注,增加了1点人气值', mobileUrl('task', array('tabpage' => 'runninga'), true));
			return;
		}
		m('message')->sendCustomNotice($openid, '感谢您的关注，恭喜您获得关注奖励');
		m('message')->sendCustomNotice($qrmember['openid'], '亲爱的' . $qrmember['nickname'] . '您的海报被' . $member_info['nickname'] . '关注,增加了1点人气值', mobileUrl('task', array('tabpage' => 'runninga'), true));
	}
	private function rankreward($member_info, $poster, $join_info, $qr, $openid, $qrmember) 
	{
		if (empty($member_info) || empty($poster) || empty($join_info) || empty($openid) || empty($qr)) 
		{
			return false;
		}
		global $_W;
		load()->func('logging');
		$reward_data = unserialize($poster['reward_data']);
		$count = $join_info['completecount'] + 1;
		if (($join_info['needcount'] == $count) && ($join_info['is_reward'] == 0)) 
		{
			$reward = serialize($reward_data['rec']);
			$sub_reward = serialize($reward_data['sub']);
			$reward_log = array('uniacid' => $_W['uniacid'], 'openid' => $qr['openid'], 'from_openid' => $openid, 'join_id' => $join_info['join_id'], 'taskid' => $qr['posterid'], 'task_type' => 1, 'subdata' => $sub_reward, 'recdata' => $reward, 'createtime' => time());
			pdo_update('ewei_shop_task_join', array('completecount' => $count, 'is_reward' => 1, 'reward_data' => $reward), array('uniacid' => $_W['uniacid'], 'join_id' => $join_info['join_id'], 'join_user' => $qr['openid'], 'task_id' => $poster['id'], 'task_type' => 1));
			pdo_insert('ewei_shop_task_log', $reward_log);
			$log_id = pdo_insertid();
			$scaner = array('uniacid' => $_W['uniacid'], 'task_user' => $qr['openid'], 'joiner_id' => $openid, 'task_id' => $qr['posterid'], 'join_id' => $join_info['join_id'], 'task_type' => 1, 'join_status' => 1, 'addtime' => time());
			pdo_insert('ewei_shop_task_joiner', $scaner);
			foreach ($reward_data as $key => $val ) 
			{
				if ($key == 'rec') 
				{
					if (isset($val['credit']) && (0 < $val['credit'])) 
					{
						m('member')->setCredit($qr['openid'], 'credit1', $val['credit'], array(0, '推荐扫码关注积分+' . $val['credit']));
					}
					if (isset($val['money']) && (0 < $val['money']['num'])) 
					{
						$pay = $val['money']['num'];
						if ($val['money']['type'] == 1) 
						{
							$pay *= 100;
						}
						m('finance')->pay($qr['openid'], $val['money']['type'], $pay, '', '任务活动推荐奖励', false);
					}
					if (isset($val['bribery']) && (0 < $val['bribery'])) 
					{
						$setting = uni_setting($_W['uniacid'], array('payment'));
						if (!(is_array($setting['payment']))) 
						{
							return error(1, '没有设定支付参数');
						}
						$sec = m('common')->getSec();
						$sec = iunserializer($sec['sec']);
						$certs = $sec;
						$wechat = $setting['payment']['wechat'];
						$sql = 'SELECT `key`,`secret` FROM ' . tablename('account_wechats') . ' WHERE `uniacid`=:uniacid limit 1';
						$row = pdo_fetch($sql, array(':uniacid' => $_W['uniacid']));
						$tid = rand(1, 1000) . time() . rand(1, 10000);
						$params = array('openid' => $qr['openid'], 'tid' => $tid, 'send_name' => '推荐奖励', 'money' => $val['bribery'], 'wishing' => '推荐奖励', 'act_name' => $poster['title'], 'remark' => '推荐奖励');
						$wechat = array('appid' => $row['key'], 'mchid' => $wechat['mchid'], 'apikey' => $wechat['apikey'], 'certs' => $certs);
						$err = m('common')->sendredpack($params, $wechat);
						if (!(is_error($err))) 
						{
							$reward = unserialize($reward);
							$reward['briberyOrder'] = $tid;
							$reward = serialize($reward);
							$upgrade = array('recdata' => $reward);
							pdo_update('ewei_shop_task_log', $upgrade, array('id' => $log_id));
						}
					}
					if (isset($val['coupon']) && !(empty($val['coupon']))) 
					{
						$cansendreccoupon = false;
						$plugin_coupon = com('coupon');
						unset($val['coupon']['total']);
						foreach ($val['coupon'] as $k => $v ) 
						{
							if ($plugin_coupon) 
							{
								if (!(empty($v['id'])) && (0 < $v['couponnum'])) 
								{
									$reccoupon = $plugin_coupon->getCoupon($v['id']);
									if (!(empty($reccoupon))) 
									{
										$cansendreccoupon = true;
									}
								}
							}
							if ($cansendreccoupon) 
							{
								$plugin_coupon->taskposter($qrmember, $v['id'], $v['couponnum']);
							}
						}
					}
				}
				else if ($key == 'sub') 
				{
					if (0 < $val['credit']) 
					{
						m('member')->setCredit($openid, 'credit1', $val['credit'], array(0, '扫码关注积分+' . $val['credit']));
					}
					if (0 < $val['money']['num']) 
					{
						$pay = $val['money']['num'];
						if ($val['money']['type'] == 1) 
						{
							$pay *= 100;
						}
						$res = m('finance')->pay($openid, $val['money']['type'], $pay, '', '任务活动奖励', false);
						if (is_error($res)) 
						{
							logging_run($res['message']);
						}
					}
					if (isset($val['coupon']) && isset($val['coupon']) && !(empty($val['coupon']))) 
					{
						$cansendreccoupon = false;
						$plugin_coupon = com('coupon');
						unset($val['coupon']['total']);
						foreach ($val['coupon'] as $k => $v ) 
						{
							if ($plugin_coupon) 
							{
								if (!(empty($v['id'])) && (0 < $v['couponnum'])) 
								{
									$reccoupon = $plugin_coupon->getCoupon($v['id']);
									if (!(empty($reccoupon))) 
									{
										$cansendreccoupon = true;
									}
								}
							}
							if ($cansendreccoupon) 
							{
								$plugin_coupon->taskposter($member_info, $v['id'], $v['couponnum']);
							}
						}
					}
				}
			}
			$default_text = pdo_fetchcolumn('SELECT `data` FROM ' . tablename('ewei_shop_task_default') . ' WHERE uniacid=:uniacid limit 1', array(':uniacid' => $_W['uniacid']));
			if (!(empty($default_text))) 
			{
				$default_text = unserialize($default_text);
				if (!(empty($default_text['successscaner']))) 
				{
					$poster['okdays'] = $join_info['failtime'];
					$poster['completecount'] = $join_info['completecount'];
					foreach ($default_text['successscaner'] as $key => $val ) 
					{
						$default_text['successscaner'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 1);
					}
					logging_run($default_text);
					if ($default_text['templateid']) 
					{
						m('message')->sendTplNotice($openid, $default_text['templateid'], $default_text['successscaner'], '');
					}
					else 
					{
						m('message')->sendCustomNotice($openid, '感谢您的关注，恭喜您获得关注奖励');
					}
				}
				else 
				{
					m('message')->sendCustomNotice($openid, '感谢您的关注，恭喜您获得关注奖励');
				}
				if (!(empty($default_text['complete']))) 
				{
					$poster['okdays'] = $join_info['failtime'];
					$poster['completecount'] = $count;
					foreach ($default_text['complete'] as $key => $val ) 
					{
						$default_text['complete'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 2);
					}
					if ($default_text['templateid']) 
					{
						m('message')->sendTplNotice($qrmember['openid'], $default_text['templateid'], $default_text['complete'], mobileUrl('task', array('tabpage' => 'complete'), true));
						return;
					}
					m('message')->sendCustomNotice($qrmember['openid'], '亲爱的' . $qrmember['nickname'] . '恭喜您完成任务获得奖励', mobileUrl('task', array('tabpage' => 'complete'), true));
					return;
				}
				m('message')->sendCustomNotice($qrmember['openid'], '亲爱的' . $qrmember['nickname'] . '恭喜您完成任务获得奖励', mobileUrl('task', array('tabpage' => 'complete'), true));
				return;
			}
			m('message')->sendCustomNotice($openid, '感谢您的关注，恭喜您获得关注奖励');
			m('message')->sendCustomNotice($openid, '亲爱的' . $qrmember['nickname'] . '恭喜您完成任务获得奖励', mobileUrl('task', array('tabpage' => 'complete'), true));
			return;
		}
		$reward = serialize($reward_data['rec']);
		$sub_reward = serialize($reward_data['sub']);
		$reward_log = array('uniacid' => $_W['uniacid'], 'openid' => $qr['openid'], 'from_openid' => $openid, 'join_id' => $join_info['join_id'], 'taskid' => $qr['posterid'], 'task_type' => 1, 'subdata' => $sub_reward, 'createtime' => time());
		pdo_update('ewei_shop_task_join', array('completecount' => $count), array('uniacid' => $_W['uniacid'], 'join_user' => $qr['openid'], 'task_id' => $poster['id'], 'task_type' => 1));
		pdo_insert('ewei_shop_task_log', $reward_log);
		$log_id = pdo_insertid();
		$scaner = array('uniacid' => $_W['uniacid'], 'task_user' => $qr['openid'], 'joiner_id' => $openid, 'task_id' => $qr['posterid'], 'join_id' => $join_info['join_id'], 'task_type' => 1, 'join_status' => 1, 'addtime' => time());
		pdo_insert('ewei_shop_task_joiner', $scaner);
		foreach ($reward_data as $key => $val ) 
		{
			if ($key == 'sub') 
			{
				if (0 < $val['credit']) 
				{
					m('member')->setCredit($openid, 'credit1', $val['credit'], array(0, '扫码关注积分+' . $val['credit']));
				}
				if (0 < $val['money']['num']) 
				{
					$pay = $val['money']['num'];
					if ($val['money']['type'] == 1) 
					{
						$pay *= 100;
					}
					$res = m('finance')->pay($openid, $val['money']['type'], $pay, '', '任务活动奖励', false);
					logging_run('submoney' . json_encode($res));
					if (is_error($res)) 
					{
						logging_run('submoney' . $res['message']);
					}
				}
				if (0 < $val['bribery']) 
				{
					$setting = uni_setting($_W['uniacid'], array('payment'));
					if (!(is_array($setting['payment']))) 
					{
						return error(1, '没有设定支付参数');
					}
					$sec = m('common')->getSec();
					$sec = iunserializer($sec['sec']);
					$certs = $sec;
					$wechat = $setting['payment']['wechat'];
					$sql = 'SELECT `key`,`secret` FROM ' . tablename('account_wechats') . ' WHERE `uniacid`=:uniacid limit 1';
					$row = pdo_fetch($sql, array(':uniacid' => $_W['uniacid']));
					$tid = rand(1, 1000) . time() . rand(1, 10000);
					$params = array('openid' => $openid, 'tid' => $tid, 'send_name' => '推荐奖励', 'money' => $val['bribery'], 'wishing' => '推荐奖励', 'act_name' => $poster['title'], 'remark' => '推荐奖励');
					$wechat = array('appid' => $row['key'], 'mchid' => $wechat['mchid'], 'apikey' => $wechat['apikey'], 'certs' => $certs);
					$err = m('common')->sendredpack($params, $wechat);
					logging_run('bribery' . json_encode($err));
					if (!(is_error($err))) 
					{
						$sub_reward = unserialize($sub_reward);
						$sub_reward['briberyOrder'] = $tid;
						$sub_reward = serialize($sub_reward);
						$upgrade = array('subdata' => $sub_reward);
						pdo_update('ewei_shop_task_log', $upgrade, array('id' => $log_id));
					}
					else 
					{
						logging_run('bribery' . $err['message']);
					}
				}
				if (isset($val['coupon']) && !(empty($val['coupon']))) 
				{
					$cansendreccoupon = false;
					$plugin_coupon = com('coupon');
					unset($val['coupon']['total']);
					foreach ($val['coupon'] as $k => $v ) 
					{
						if ($plugin_coupon) 
						{
							$cansendreccoupon = false;
							if (!(empty($v['id'])) && (0 < $v['couponnum'])) 
							{
								$reccoupon = $plugin_coupon->getCoupon($v['id']);
								if (!(empty($reccoupon))) 
								{
									$cansendreccoupon = true;
								}
							}
						}
						if ($cansendreccoupon) 
						{
							$plugin_coupon->taskposter($member_info, $v['id'], $v['couponnum']);
						}
					}
				}
			}
		}
		$default_text = pdo_fetchcolumn('SELECT `data` FROM ' . tablename('ewei_shop_task_default') . ' WHERE uniacid=:uniacid limit 1', array(':uniacid' => $_W['uniacid']));
		if (!(empty($default_text))) 
		{
			$default_text = unserialize($default_text);
			if (!(empty($default_text['successscaner']))) 
			{
				$poster['okdays'] = $join_info['failtime'];
				$poster['completecount'] = $join_info['completecount'];
				foreach ($default_text['successscaner'] as $key => $val ) 
				{
					$default_text['successscaner'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 1);
				}
				if ($default_text['templateid']) 
				{
					m('message')->sendTplNotice($openid, $default_text['templateid'], $default_text['successscaner'], '');
				}
				else 
				{
					m('message')->sendCustomNotice($openid, '感谢您的关注，恭喜您获得关注奖励');
				}
			}
			else 
			{
				m('message')->sendCustomNotice($openid, '感谢您的关注，恭喜您获得关注奖励');
			}
			if (!(empty($default_text['successtasker']))) 
			{
				$poster['okdays'] = $join_info['failtime'];
				$poster['completecount'] = $count;
				foreach ($default_text['successtasker'] as $key => $val ) 
				{
					$default_text['successtasker'][$key]['value'] = $this->model->notice_complain($val['value'], $qrmember, $poster, $member_info, 2);
				}
				if ($default_text['templateid']) 
				{
					m('message')->sendTplNotice($qrmember['openid'], $default_text['templateid'], $default_text['successtasker'], mobileUrl('task', array('tabpage' => 'runninga'), true));
					return;
				}
				m('message')->sendCustomNotice($qrmember['openid'], '亲爱的' . $qrmember['nickname'] . '您的海报被' . $member_info['nickname'] . '关注,增加了1点人气值', mobileUrl('task', array('tabpage' => 'runninga'), true));
				return;
			}
			m('message')->sendCustomNotice($qrmember['openid'], '亲爱的' . $qrmember['nickname'] . '您的海报被' . $member_info['nickname'] . '关注,增加了1点人气值', mobileUrl('task', array('tabpage' => 'runninga'), true));
			return;
		}
		m('message')->sendCustomNotice($openid, '感谢您的关注，恭喜您获得关注奖励');
		m('message')->sendCustomNotice($qrmember['openid'], '亲爱的' . $qrmember['nickname'] . '您的海报被' . $member_info['nickname'] . '关注,增加了1点人气值', mobileUrl('task', array('tabpage' => 'runninga'), true));
	}
}
?>