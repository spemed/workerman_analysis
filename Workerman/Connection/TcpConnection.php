<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Connection;

use Workerman\Events\EventInterface;
use Workerman\Protocols\ProtocolInterface;
use Workerman\Worker;
use \Exception;

/**
 * TcpConnection.
 */
class TcpConnection extends ConnectionInterface
{
    /**
     * Read buffer size.
     *
     * @var int
     */
    const READ_BUFFER_SIZE = 65535;

    /**
     * Status initial.
     *
     * @var int
     */
    const STATUS_INITIAL = 0;

    /**
     * Status connecting.
     *
     * @var int
     */
    const STATUS_CONNECTING = 1;

    /**
     * Status connection established.
     *
     * @var int
     */
    const STATUS_ESTABLISHED = 2;

    /**
     * Status closing.
     *
     * @var int
     */
    const STATUS_CLOSING = 4;

    /**
     * Status closed.
     *
     * @var int
     */
    const STATUS_CLOSED = 8;

    /**
     * Emitted when data is received.
     *
     * @var callable
     */
    public $onMessage = null;

    /**
     * Emitted when the other end of the socket sends a FIN packet.
     *
     * @var callable
     */
    public $onClose = null;

    /**
     * Emitted when an error occurs with connection.
     *
     * @var callable
     */
    public $onError = null;

    /**
     * Emitted when the send buffer becomes full.
     *
     * @var callable
     */
    public $onBufferFull = null;

    /**
     * Emitted when the send buffer becomes empty.
     *
     * @var callable
     */
    public $onBufferDrain = null;

    /**
     * Application layer protocol.
     * The format is like this Workerman\\Protocols\\Http.
     *
     * @var ProtocolInterface
     */
    public $protocol = null;

    /**
     * Transport (tcp/udp/unix/ssl).
     *
     * @var string
     */
    public string $transport = 'tcp';

    /**
     * Which worker belong to.
     *
     * @var Worker
     */
    public ?Worker $worker = null;

    /**
     * Bytes read.
     *
     * @var int
     */
    public int $bytesRead = 0;

    /**
     * Bytes written.
     *
     * @var int
     */
    public int $bytesWritten = 0;

    /**
     * Connection->id.
     *
     * @var int
     */
    public int $id = 0;

    /**
     * A copy of $worker->id which used to clean up the connection in worker->connections
     * @var int
     */
    protected int $_id = 0;

    /**
     * Sets the maximum send buffer size for the current connection.
     * OnBufferFull callback will be emited When the send buffer is full.
     * 每个链接上发送的字节数超过$maxSendBufferSize则关闭连接,同时调用OnBufferFull回调。
     * @var int
     */
    public int $maxSendBufferSize = 1048576;

    /**
     * Default send buffer size.
     * 默认套接字发送缓冲区的大小
     * @var int
     */
    public static int $defaultMaxSendBufferSize = 1048576;

    /**
     * Sets the maximum acceptable packet size for the current connection.
     * 包长度
     * @var int
     */
    public int $maxPackageSize = 1048576;
    
    /**
     * Default maximum acceptable packet size.
     *
     * @var int
     */
    public static int $defaultMaxPackageSize = 10485760;

    /**
     * Id recorder.
     * 静态变量,id记录器
     * @var int
     */
    protected static int $_idRecorder = 1;

    /**
     * Socket
     *
     * @var resource
     */
    protected $_socket = null;

    /**
     * Send buffer.
     * 用户态的写缓冲区
     * @var string
     */
    protected string $_sendBuffer = '';

    /**
     * Receive buffer.
     * 用户态的读缓冲区
     * @var string
     */
    protected string $_recvBuffer = '';

    /**
     * Current package length.
     *
     * @var int
     */
    protected int $_currentPackageLength = 0;

    /**
     * Connection status.
     *
     * @var int
     */
    protected int $_status = self::STATUS_ESTABLISHED;

    /**
     * Remote address.
     *
     * @var string
     */
    protected string $_remoteAddress = '';

    /**
     * Is paused.
     *
     * @var bool
     */
    protected bool $_isPaused = false;

    /**
     * SSL handshake completed or not.
     * 是否启动ssl握手
     * @var bool
     */
    protected bool $_sslHandshakeCompleted = false;

    /**
     * All connection instances.
     * 所有连接对象的集合
     * @var array
     */
    public static array $connections = array();

    /**
     * Status to string.
     *
     * @var array
     */
    public static array $_statusToString = array(
        self::STATUS_INITIAL => 'INITIAL',
        self::STATUS_CONNECTING => 'CONNECTING',
        self::STATUS_ESTABLISHED => 'ESTABLISHED',
        self::STATUS_CLOSING => 'CLOSING',
        self::STATUS_CLOSED => 'CLOSED',
    );

    /**
     * Construct.
     *
     * @param resource $socket
     * @param string   $remote_address
     */
    public function __construct($socket, $remote_address = '')
    {
        ++self::$statistics['connection_count'];
        //静态的id记录器自增,分配给id和_id
        $this->id = $this->_id = self::$_idRecorder++;
        //自增的id记录器达到PHP_INT_MAX，则把self::$_idRecorder赋值为0
        if(self::$_idRecorder === \PHP_INT_MAX){
            self::$_idRecorder = 0;
        }
        $this->_socket = $socket;
        //设置为非阻塞io
        \stream_set_blocking($this->_socket, 0);
        // Compatible with hhvm
        // 兼容hhvm的多线程mode
        if (\function_exists('stream_set_read_buffer')) {
            //buffer设置为0，使用fread进行读取操作会保证线程安全[在当前的fread操作完成之前不会被其他线程/进程访问]
            \stream_set_read_buffer($this->_socket, 0);
        }
        //为套接字注册读事件
        Worker::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
        $this->maxSendBufferSize        = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize           = self::$defaultMaxPackageSize;
        //设置远端地址
        $this->_remoteAddress           = $remote_address;
        //往静态$connections数组添加当前tcpConnection的实例
        static::$connections[$this->id] = $this;
    }

    /**
     * Get status.
     *
     * @param bool $raw_output
     *
     * @return int|string
     */
    public function getStatus($raw_output = true)
    {
        if ($raw_output) {
            return $this->_status;
        }
        return self::$_statusToString[$this->_status];
    }

    /**
     * Sends data on the connection.
     *
     * @param mixed $send_buffer
     * @param bool  $raw
     * @return bool|null
     */
    public function send($send_buffer, $raw = false)
    {
        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return false;
        }

        // Try to call protocol::encode($send_buffer) before sending.
        if (false === $raw && $this->protocol !== null) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return;
            }
        }

        if ($this->_status !== self::STATUS_ESTABLISHED ||
            ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true)
        ) {
            if ($this->_sendBuffer && $this->bufferIsFull()) {
                ++self::$statistics['send_fail'];
                return false;
            }
            $this->_sendBuffer .= $send_buffer;
            $this->checkBufferWillFull();
            return;
        }

        // Attempt to send data directly.
        if ($this->_sendBuffer === '') {
            if ($this->transport === 'ssl') {
                Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                $this->_sendBuffer = $send_buffer;
                $this->checkBufferWillFull();
                return;
            }
            $len = 0;
            try {
                $len = @\fwrite($this->_socket, $send_buffer);
            } catch (\Exception $e) {
                Worker::log($e);
            } catch (\Error $e) {
                Worker::log($e);
            }
            // send successful.
            if ($len === \strlen($send_buffer)) {
                $this->bytesWritten += $len;
                return true;
            }
            // Send only part of the data.
            if ($len > 0) {
                $this->_sendBuffer = \substr($send_buffer, $len);
                $this->bytesWritten += $len;
            } else {
                // Connection closed?
                if (!\is_resource($this->_socket) || \feof($this->_socket)) {
                    ++self::$statistics['send_fail'];
                    if ($this->onError) {
                        try {
                            \call_user_func($this->onError, $this, \WORKERMAN_SEND_FAIL, 'client closed');
                        } catch (\Exception $e) {
                            Worker::log($e);
                            exit(250);
                        } catch (\Error $e) {
                            Worker::log($e);
                            exit(250);
                        }
                    }
                    $this->destroy();
                    return false;
                }
                $this->_sendBuffer = $send_buffer;
            }
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            // Check if the send buffer will be full.
            $this->checkBufferWillFull();
            return;
        }

        if ($this->bufferIsFull()) {
            ++self::$statistics['send_fail'];
            return false;
        }

        $this->_sendBuffer .= $send_buffer;
        // Check if the send buffer is full.
        $this->checkBufferWillFull();
    }

    /**
     * Get remote IP.
     *
     * @return string
     */
    public function getRemoteIp()
    {
        $pos = \strrpos($this->_remoteAddress, ':');
        if ($pos) {
            return (string) \substr($this->_remoteAddress, 0, $pos);
        }
        return '';
    }

    /**
     * Get remote port.
     *
     * @return int
     */
    public function getRemotePort()
    {
        if ($this->_remoteAddress) {
            return (int) \substr(\strrchr($this->_remoteAddress, ':'), 1);
        }
        return 0;
    }

    /**
     * Get remote address.
     *
     * @return string
     */
    public function getRemoteAddress()
    {
        return $this->_remoteAddress;
    }

    /**
     * Get local IP.
     *
     * @return string
     */
    public function getLocalIp()
    {
        $address = $this->getLocalAddress();
        $pos = \strrpos($address, ':');
        if (!$pos) {
            return '';
        }
        return \substr($address, 0, $pos);
    }

    /**
     * Get local port.
     *
     * @return int
     */
    public function getLocalPort()
    {
        $address = $this->getLocalAddress();
        $pos = \strrpos($address, ':');
        if (!$pos) {
            return 0;
        }
        return (int)\substr(\strrchr($address, ':'), 1);
    }

    /**
     * Get local address.
     *
     * @return string
     */
    public function getLocalAddress()
    {
        return (string)@\stream_socket_get_name($this->_socket, false);
    }

    /**
     * Get send buffer queue size.
     *
     * @return integer
     */
    public function getSendBufferQueueSize()
    {
        return \strlen($this->_sendBuffer);
    }

    /**
     * Get recv buffer queue size.
     *
     * @return integer
     */
    public function getRecvBufferQueueSize()
    {
        return \strlen($this->_recvBuffer);
    }

    /**
     * Is ipv4.
     *
     * return bool.
     */
    public function isIpV4()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') === false;
    }

    /**
     * Is ipv6.
     *
     * return bool.
     */
    public function isIpV6()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') !== false;
    }

    /**
     * Pauses the reading of data. That is onMessage will not be emitted. Useful to throttle back an upload.
     *
     * @return void
     */
    public function pauseRecv()
    {
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_READ);
        $this->_isPaused = true;
    }

    /**
     * Resumes reading after a call to pauseRecv.
     *
     * @return void
     */
    public function resumeRecv()
    {
        if ($this->_isPaused === true) {
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
            $this->_isPaused = false;
            $this->baseRead($this->_socket, false);
        }
    }



    /**
     * Base read handler.
     *
     * @param resource $socket
     * @param bool $check_eof 是否需要检查EOF(收到fin包)
     * @return void
     */
    public function baseRead($socket, $check_eof = true)
    {
        // SSL handshake.
        if ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true) {
            if ($this->doSslHandshake($socket)) {
                $this->_sslHandshakeCompleted = true;
                if ($this->_sendBuffer) {
                    Worker::$globalEvent->add($socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                }
            } else {
                return;
            }
        }

        $buffer = '';
        try {
            $buffer = @\fread($socket, self::READ_BUFFER_SIZE);
        } catch (\Exception $e) {}


        // Check connection closed.
        if ($buffer === '' || $buffer === false) {
            //如果需要检测fin包,且收到了fin包的情况下释放连接,清除事件循环中心中的事件[避免close wait]
            if ($check_eof && (\feof($socket) || !\is_resource($socket) || $buffer === false)) {
                $this->destroy();
                return;
            }
        } else {
            //更新已经读取的字节数
            $this->bytesRead += \strlen($buffer);
            //读缓冲区追加buffer
            $this->_recvBuffer .= $buffer;
        }

        // If the application layer protocol has been set up.
        // 取得应用层协议的处理类
        if ($this->protocol !== null) {
            $parser = $this->protocol;
            //tcp面向字节流传输,数据本身是无边界的,需要在应用层协商好数据包的长度,然后从读缓冲区中取出数据时
            //根据recvBuffer的长度[从读缓冲区中读出来的数据]和数据包长度的大小做判断,会有以下几种情况
            //1.需要的数据包的长度等于recvBuffer的长度 --> 收到了完整的数据包
            //2.需要的数据包的长度大于recvBuffer的长度 --> 数据包不完整,需要继续等待对端发送tcp分节 (所谓"半包")
            //3.需要的数据包的长度小于recvBuffer的长度 --> 下一个数据包的数据和本数据包同时到达,需要对recvBuffer做截断 (所谓"沾包")
            while ($this->_recvBuffer !== '' && !$this->_isPaused) {
                // 半包
                // The current packet length is known.
                // 如果当前packet的长度大于累计接收到的recvBuffer的长度,则直接break跳出循环
                // 等待获取到完整的packet
                if ($this->_currentPackageLength) {
                    // Data is not enough for a package.
                    if ($this->_currentPackageLength > \strlen($this->_recvBuffer)) {
                        break;
                    }
                } else {
                    // Get current package length.
                    try {
                        //调用应用层的parse进行解析
                        //得到当前packet的长度
                        $this->_currentPackageLength = $parser::input($this->_recvBuffer, $this);
                        //echo "\$this->_currentPackageLength=".$this->_currentPackageLength.PHP_EOL;
                        //echo "\$this->_recvBufferLength=".strlen($this->_recvBuffer).PHP_EOL;
                    } catch (\Exception $e) {}
                    // The packet length is unknown.
                    // _recvBuffer是残缺的,不足以从中分析出数据包的长度
                    // break跳出循环,等待事件循环中心下一次返回可读事件
                    if ($this->_currentPackageLength === 0) {
                        break;
                    } elseif ($this->_currentPackageLength > 0 && $this->_currentPackageLength <= $this->maxPackageSize) {
                        // Data is not enough for a package.
                        // 应用层解析出的数据包长度大于累计收到的_recvBuffer的长度
                        // 说明数据包尚未完整,break跳出循环等待事件循环中心返回下一次可读事件
                        if ($this->_currentPackageLength > \strlen($this->_recvBuffer)) {
                            break;
                        }
                    } // Wrong package.
                    else {
                        Worker::safeEcho('Error package. package_length=' . \var_export($this->_currentPackageLength, true));
                        $this->destroy();
                        return;
                    }
                }

                // The data is enough for a packet.
                ++self::$statistics['total_request'];
                // The current packet length is equal to the length of the buffer.
                // 得到了完整的数据包
                if (\strlen($this->_recvBuffer) === $this->_currentPackageLength) {
                    $one_request_buffer = $this->_recvBuffer;
                    $this->_recvBuffer  = '';
                } else {
                    // 处理数据的边界问题(沾包)
                    // recvBuff的长度大于当前数据包的长度,需要对齐进行截断
                    // Get a full package from the buffer.
                    $one_request_buffer = \substr($this->_recvBuffer, 0, $this->_currentPackageLength);
                    // Remove the current package from the receive buffer.
                    // 裁剪剩余的recvBuffer重新赋值
                    $this->_recvBuffer = \substr($this->_recvBuffer, $this->_currentPackageLength);
                }
                // Reset the current packet length to 0.
                // 当前数据包的长度置0
                $this->_currentPackageLength = 0;
                //没有注册onMessage回调直接continue跳出
                if (!$this->onMessage) {
                    continue;
                }
                try {
                    // Decode request buffer before Emitting onMessage callback.
                    // 字节流数据解码后调用on_message回调
                    \call_user_func($this->onMessage, $this, $parser::decode($one_request_buffer, $this));
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            return;
        }

        if ($this->_recvBuffer === '' || $this->_isPaused) {
            return;
        }

        // Applications protocol is not set.
        ++self::$statistics['total_request'];
        if (!$this->onMessage) {
            $this->_recvBuffer = '';
            return;
        }
        //如果没有配置应用层协议,则直接把字节数据转发给TcpConnection对象的onMessage回调
        try {
            \call_user_func($this->onMessage, $this, $this->_recvBuffer);
        } catch (\Exception $e) {
            Worker::log($e);
            exit(250);
        }
        // Clean receive buffer.
        $this->_recvBuffer = '';
    }

    /**
     * Base write handler.
     *
     * @return void|bool
     */
    public function baseWrite()
    {
        \set_error_handler(function(){});
        if ($this->transport === 'ssl') {
            $len = @\fwrite($this->_socket, $this->_sendBuffer, 8192);
        } else {
            $len = @\fwrite($this->_socket, $this->_sendBuffer);
        }
        \restore_error_handler();
        if ($len === \strlen($this->_sendBuffer)) {
            $this->bytesWritten += $len;
            Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);
            $this->_sendBuffer = '';
            // Try to emit onBufferDrain callback when the send buffer becomes empty.
            if ($this->onBufferDrain) {
                try {
                    \call_user_func($this->onBufferDrain, $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            return true;
        }
        if ($len > 0) {
            $this->bytesWritten += $len;
            $this->_sendBuffer = \substr($this->_sendBuffer, $len);
        } else {
            ++self::$statistics['send_fail'];
            $this->destroy();
        }
    }

    /**
     * SSL handshake.
     *
     * @param $socket
     * @return bool
     */
    public function doSslHandshake($socket){
        if (\feof($socket)) {
            $this->destroy();
            return false;
        }
        $async = $this instanceof AsyncTcpConnection;
        
        /**
          *  We disabled ssl3 because https://blog.qualys.com/ssllabs/2014/10/15/ssl-3-is-dead-killed-by-the-poodle-attack.
          *  You can enable ssl3 by the codes below.
          */
        /*if($async){
            $type = STREAM_CRYPTO_METHOD_SSLv2_CLIENT | STREAM_CRYPTO_METHOD_SSLv23_CLIENT | STREAM_CRYPTO_METHOD_SSLv3_CLIENT;
        }else{
            $type = STREAM_CRYPTO_METHOD_SSLv2_SERVER | STREAM_CRYPTO_METHOD_SSLv23_SERVER | STREAM_CRYPTO_METHOD_SSLv3_SERVER;
        }*/
        
        if($async){
            $type = \STREAM_CRYPTO_METHOD_SSLv2_CLIENT | \STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
        }else{
            $type = \STREAM_CRYPTO_METHOD_SSLv2_SERVER | \STREAM_CRYPTO_METHOD_SSLv23_SERVER;
        }
        
        // Hidden error.
        \set_error_handler(function($errno, $errstr, $file){
            if (!Worker::$daemonize) {
                Worker::safeEcho("SSL handshake error: $errstr \n");
            }
        });
        $ret = \stream_socket_enable_crypto($socket, true, $type);
        \restore_error_handler();
        // Negotiation has failed.
        if (false === $ret) {
            $this->destroy();
            return false;
        } elseif (0 === $ret) {
            // There isn't enough data and should try again.
            return 0;
        }
        if (isset($this->onSslHandshake)) {
            try {
                \call_user_func($this->onSslHandshake, $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
        return true;
    }

    /**
     * This method pulls all the data out of a readable stream, and writes it to the supplied destination.
     *
     * @param self $dest
     * @return void
     */
    public function pipe(self $dest)
    {
        $source              = $this;
        $this->onMessage     = function ($source, $data) use ($dest) {
            $dest->send($data);
        };
        $this->onClose       = function ($source) use ($dest) {
            $dest->close();
        };
        $dest->onBufferFull  = function ($dest) use ($source) {
            $source->pauseRecv();
        };
        $dest->onBufferDrain = function ($dest) use ($source) {
            $source->resumeRecv();
        };
    }

    /**
     * Remove $length of data from receive buffer.
     *
     * @param int $length
     * @return void
     */
    public function consumeRecvBuffer($length)
    {
        $this->_recvBuffer = \substr($this->_recvBuffer, $length);
    }

    /**
     * Close connection.
     *
     * @param mixed $data
     * @param bool $raw
     * @return void
     */
    public function close($data = null, $raw = false)
    {
        if($this->_status === self::STATUS_CONNECTING){
            $this->destroy();
            return;
        }

        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return;
        }

        if ($data !== null) {
            $this->send($data, $raw);
        }

        $this->_status = self::STATUS_CLOSING;
        
        if ($this->_sendBuffer === '') {
            $this->destroy();
        } else {
            $this->pauseRecv();
        }
    }

    /**
     * Get the real socket.
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->_socket;
    }

    /**
     * Check whether the send buffer will be full.
     *
     * @return void
     */
    protected function checkBufferWillFull()
    {
        if ($this->maxSendBufferSize <= \strlen($this->_sendBuffer)) {
            if ($this->onBufferFull) {
                try {
                    \call_user_func($this->onBufferFull, $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
        }
    }

    /**
     * Whether send buffer is full.
     *
     * @return bool
     */
    protected function bufferIsFull()
    {
        // Buffer has been marked as full but still has data to send then the packet is discarded.
        if ($this->maxSendBufferSize <= \strlen($this->_sendBuffer)) {
            if ($this->onError) {
                try {
                    \call_user_func($this->onError, $this, \WORKERMAN_SEND_FAIL, 'send buffer full and drop package');
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            return true;
        }
        return false;
    }
    
    /**
     * Whether send buffer is Empty.
     *
     * @return bool
     */
    public function bufferIsEmpty()
    {
    	return empty($this->_sendBuffer);
    }

    /**
     * Destroy connection.
     *
     * @return void
     */
    public function destroy()
    {
        // Avoid repeated calls.
        // 先将_status设置为self::STATUS_CLOSED
        // 如果被重复调用则不执行后续逻辑
        if ($this->_status === self::STATUS_CLOSED) {
            return;
        }
        // Remove event listener.
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_READ);
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);

        // Close socket.
        try {
            @\fclose($this->_socket);
        } catch (\Exception $e) {}

        //当前连接已经关闭
        $this->_status = self::STATUS_CLOSED;
        // Try to emit onClose callback.
        // 执行onClose回调[传输层]
        if ($this->onClose) {
            try {
                \call_user_func($this->onClose, $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            }
        }
        // Try to emit protocol::onClose
        // 尝试执行应用层的onClose回调
        if ($this->protocol && \method_exists($this->protocol, 'onClose')) {
            try {
                \call_user_func(array($this->protocol, 'onClose'), $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            }
        }
        //清空读写缓冲区
        $this->_sendBuffer = $this->_recvBuffer = '';
        $this->_currentPackageLength = 0;
        $this->_isPaused = $this->_sslHandshakeCompleted = false;
        if ($this->_status === self::STATUS_CLOSED) {
            // Cleaning up the callback to avoid memory leaks.
            $this->onMessage = $this->onClose = $this->onError = $this->onBufferFull = $this->onBufferDrain = null;
            // Remove from worker->connections.
            if ($this->worker) {
                unset($this->worker->connections[$this->_id]);
            }
            unset(static::$connections[$this->_id]);
        }
    }

    /**
     * Destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        static $mod;
        self::$statistics['connection_count']--;
        if (Worker::getGracefulStop()) {
            if (!isset($mod)) {
                $mod = \ceil((self::$statistics['connection_count'] + 1) / 3);
            }

            if (0 === self::$statistics['connection_count'] % $mod) {
                Worker::log('worker[' . \posix_getpid() . '] remains ' . self::$statistics['connection_count'] . ' connection(s)');
            }

            if(0 === self::$statistics['connection_count']) {
                Worker::stopAll();
            }
        }
    }
}
