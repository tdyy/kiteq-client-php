<?php
namespace Momo\Kiteq;

class Client {

	public $conn;

	public $ip;

	public $port;

	public $group;

	public $secret;

	public $timeout; //miles

	public $seq = 0;

	private $extension = false;


	const CMD_CONN_META = 0x02;
	const CMD_CONN_AUTH = 0x03;
	const CMD_MESSAGE_STORE_ACK = 0x04;
	const CMD_STRING_MESSAGE = 0x12;

	public function __construct() {
		// 引入消息定义
		if (class_exists('ProtobufMessage')) {
			$this->extension = true;
			require_once __DIR__ . DIRECTORY_SEPARATOR . "pb_proto_kiteq_native.php";
		} else {
			require_once "../thirdparty/message/pb_message.php";
			require_once __DIR__ . DIRECTORY_SEPARATOR . "pb_proto_kiteq_local.php";
		}

	}

	public function connect() {
		if (!isset($this->timeout)) {
			$this->timeout = 1000;
		}
		$this->conn = fsockopen($this->ip, $this->port, $ec, $em, floatval($this->timeout/1000.0));
		if ($this->conn == false) {
			throw new \Exception("链接kiteq server失败 $ec $em");
		}
	}

	public function greet() {
		$greet = new \ConnMeta();
		$greet->set_groupId($this->group);
		$greet->set_secretKey($this->secret);
		// 发送
		$this->innerSend($greet->SerializeToString(), self::CMD_CONN_META);

		// 接受
		list($type, $data)= $this->innerGet();
		if ($type != self::CMD_CONN_AUTH) {
			throw new \Exception("kiteq 验证错误 MessageType $type");
		}
		$ack = new \ConnAuthAck();
		$ack->ParseFromString($data);
	}

	public function publish($topic, $type, $msg) {
		$msgEntity = new \StringMessage();
		$msgHeader = new \Header();
		$msgHeader->set_groupId($this->group);
		$msgHeader->set_commit(true);
		$msgHeader->set_deliverLimit(100);
		$msgHeader->set_expiredTime(-1);
		$msgHeader->set_fly(false);
		$msgHeader->set_topic($topic);
		$msgHeader->set_messageId(uniqid());
		$msgHeader->set_messageType($type);
		$msgEntity->set_header($msgHeader);
		$msgEntity->set_body($msg);
		$send = $msgEntity->SerializeToString();
		// 发送
		$this->innerSend($send, self::CMD_STRING_MESSAGE);

		// 接受
		list($type, $data)= $this->innerGet();
		if ($type != self::CMD_MESSAGE_STORE_ACK) {
			throw new \Exception("kiteq 验证错误 MessageType $type");
		}
		$ack = new \MessageStoreAck();
		$ack->ParseFromString($data);
	}

	private function innerSend($data, $type) {
		$len = strlen($data);
		$write = "";
		$write .= pack("N", $this->seq++);
		$write .= pack("C1", $type);
		$write .= pack("N", $len);
		$write .= $data."\r\n";
		fwrite($this->conn, $write);
	}

	private function innerGet() {
		$r = fread($this->conn, 4);
		if ($r == false) {
			throw new \Exception("读取seq错误");
		}
		$r = fread($this->conn, 1);
		if ($r == false) {
			throw new \Exception("读取type错误");
		}
		$type = ord($r);
		$bs = fread($this->conn, 4);
		if ($r == false) {
			throw new \Exception("读取length错误");
		}
		$length = ord($bs[0])<<24 | ord($bs[1]) << 16 | ord($bs[2]) << 8 | ord($bs[3]);
		$data = fread($this->conn, $length);
		// skip \r\n
		$r = fread($this->conn, 2);
		if ($r != "\r\n") {
			throw new \Exception("读取\\r\\n失败");
		}
		return array($type, $data);
	}
}

