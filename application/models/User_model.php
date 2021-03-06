<?php
class User_model extends Object {
	
	var $name = '', $email, $password, $roles = array(), $is_group = false, $object_name;
	
	static $fields = array(
		'name'=>'',
		'email'=>null,
		'password'=>'',
		'roles'=>'',
		'is_group'=>false,
		'last_ip'=>'',
		'last_login'=>null
	);
	
	function __construct($data = null, array $args = array()){
		parent::__construct($data, $args);
	}
	
	function initialize($id = null){
		
		isset($id) && $this->session->user_id = intval($id);
		
		if(is_null($this->session->user_id) && $this->session->userdata('user_id')){
			$this->session->user_id = intval($this->session->userdata('user_id'));
		}
		
		if(!$this->session->user_id){
			return;
		}
		
		try{
			$user = $this->get($this->session->user_id, array('with'=>null), false);
		}catch(Exception $e){
			if($e->getCode() === 404){
				$this->sessionLogout();
				throw new Exception('unauthorized', 401);
			}
		}
		
		$user['roles'] = $this->_parse_roles($user['roles']);
		
		$this->session->user = $user;
		$this->session->user_name = $user['name'];
		$this->session->user_object_name = $user['object_name'];
		
		$this->session->user_roles = $user['roles'];
		
		array_push($this->session->group_ids, $this->session->user_id);
		
		$this->_get_parent_group(array($this->session->user_id));
		
		$this->session->user['roles'] = $this->session->user_roles;
		
	}
	
	function _parse_roles($roles){
		
		$roles_decoded = json_decode($roles);
		
		if(is_array($roles_decoded)){
			return $roles_decoded;
		}
		elseif($roles){
			return explode(',', $roles);
		}
		else{
			return array();
		}
	}
	
	/**
	 * 
	 * @todo 应当避免掉入死循环
	 * @todo 应当在数据库建立缓存
	 */
	function _get_parent_group($children = array()){

		$parents = $this->query(array(
			'children'=>array($children),
			'found_rows'=>false,
			'limit'=>false,
			'order_by'=>false
		), false);

		$this->session->groups = array_merge($this->session->groups, $parents);
		$parent_group_ids = array_map('intval', array_column($parents, 'id'));
		$this->session->group_ids = array_merge($this->session->group_ids, $parent_group_ids);
		
		$this->session->user_roles = array_merge($this->session->user_roles, array_reduce(
			array_map(array($this, '_parse_roles'), array_column($parents, 'roles')),
			function($result, $item){
				return array_merge($result, $item);
			}, array()
		));
			
		if($parent_group_ids){
			$this->_get_parent_group($parent_group_ids);
		}
		
	}

	function get($id = null, array $args = array(), $permission_check = true){
		
		if(is_null($id)){
			$id = $this->id;
		}
		
		$object = parent::get($id, $args, $permission_check);
		
		$user = $this->db->select('user.id, user.name, user.email, user.roles, user.is_group, user.last_ip, user.last_login')->from('user')->where('id', $id)->get()->row_array();
		
		$user['id'] = intval($user['id']);
		$user['is_group'] = boolval($user['is_group']);
		
		if(!$user){
			throw new Exception(lang('user').' '.$id.' '.lang('not_found'), 404);
		}
		
		$user['object_name'] = $object['name'];

		return array_merge($object, $user);
	}
	
	function query(array $args = array(), $permission_check = true){
		
		$this->db->join('user','user.id = object.id','inner')->select('user.name, user.email, user.roles, user.is_group, user.last_ip, user.last_login');
		
		if(array_key_exists('is_group', $args)){
			$this->db->where('user.is_group', $args['is_group']);
		}
		
		return parent::query($args, $permission_check);
	}
	
	/**
	 * 
	 * @param array $data
	 * @param array $args
	 *	object 要添加为用户的对象ID，如果不指定，将新建一个对象
	 * @return int
	 * @todo 添加的用户是重复的，且没有指定对象时，会先成功创建对象，然后插入user表时失败，这样会在产生一个冗余对象
	 */
	function add(array $data, array $args = array()){
		
		!array_key_exists('type', $data) && $data['type'] = 'user';
		
		if(array_key_exists('object', $args)){
			$insert_id = $args['object'];
		}
		else{
			$insert_id = parent::add($data);
		}

		$data = array_merge(self::$fields, array_intersect_key($data, self::$fields));
		
		$data['id'] = $insert_id;
		$data['company'] = get_instance()->company->id;

		$this->db->insert('user',$data);
		
		return $insert_id;
	}
	
	function update(array $data, array $args = array()){
		if(array_key_exists('include_object', $args)){
			parent::update($data);
		}
		return $this->db->update('user', array_intersect_key($data, self::$fields), array('id'=>$this->id));
	}
	
	function remove(){
		$this->db->delete('user', array('id'=>$this->id));
		parent::remove();
	}
	
	function getRelative(array $args = array()) {
		$args['is_user'] = true;
		return parent::getRelative($args);
	}
	
	function verify($username,$password){
		
		$this->db
			->from('user')
			->where('name', $username)
			->where('company', get_instance()->company->id)
			->where('password', $password);
		
		$user=$this->db->get()->row_array();
		
		if(!$user){
			throw new Exception('login_info_error', 403);
		}
		
		return $user;
	}
	
	/**
	 * 根据uid直接为其设置登录状态
	 */
	function sessionLogin($uid){
		$this->db->select('user.*')
			->from('user')
			->where('user.id',$uid);

		$user=$this->db->get()->row_array();
		
		if($user){
			$this->initialize($user['id']);
			$this->session->set_userdata('user_id', $user['id']);
			$this->update(array(
				'last_ip'=>$this->session->userdata('ip_address'),
				'last_login'=>date('Y-m-d H:i:s')
			));
			return true;
		}
		
		return false;
	}

	/**
	 * 登出当前用户
	 */
	function sessionLogout(){
		$this->session->sess_destroy();
		$this->initialize();
	}

	/**
	 * 判断是否以某用户组登录
	 * $check_type要检查的用户组,NULL表示只检查是否登录
	 */
	function isLogged($group=NULL){
		if(is_null($group)){
			if(empty($this->session->user_id)){
				return false;
			}
		}elseif(empty($this->session->user_roles) || !in_array($group,$this->session->user_roles)){
			return false;
		}

		return true;
	}
	
	/**
	 * set or get a user config value
	 * or get all config values of a user
	 * json_decode/encode automatically
	 * @param string $key
	 * @param mixed $value
	 */
	function config($key = null, $value = null){
		
		if(is_null($key)){
			
			$this->db->from('user_config')->where('user',$this->session->user_id);

			$config = array_column($this->db->get()->result_array(), 'value', 'key');

			return array_map(function($value){
				$value_decoded = json_decode($value, JSON_OBJECT_AS_ARRAY);
				return $value_decoded === null ? $value : $value_decoded;
				
			}, $config);
			
		}
		elseif(is_null($value)){
			
			$row = $this->db->select('id,value')
				->from('user_config')
				->where('user', $this->session->user_id)
				->where('key', $key)
				->get()->row();

			if($row){
				$json_value = json_decode($row->value);
				
				return $json_value;
			}
			else{
				return false;
			}
		}
		else{
			
			$value = json_encode($value);
			
			return $this->db->upsert('user_config', array('user'=>$this->session->user_id, 'key'=>$key, 'value'=>$value));
		}
	}

}
?>
