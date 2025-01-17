<?php

namespace Ddeboer\Imap;

use Ddeboer\Imap\Exception\Exception;
use Ddeboer\Imap\Exception\MailboxOpenException;
use Ddeboer\Transcoder\Transcoder;
/**
 * An IMAP mailbox (commonly referred to as a ‘folder’)
 *
 */
class Mailbox implements \IteratorAggregate
{
    private $mailbox;
    private $name;
    private $connection;

    private $lastException;
    /**
     * Constructor
     *
     * @param string     $name       Mailbox object from imap_getmailboxes
     * @param Connection $connection IMAP connection
     */
    public function __construct($mboxInfo, Connection $connection)
    {
        $this->mailbox =  $mboxInfo;
        $this->connection = $connection;

        $name = $mboxInfo->name;
        $this->name = substr($name, strpos($name, '}')+1);
    }

    public function getPath()
    {
        return $this->mailbox->name;
    }

    /**
     * Get mailbox name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    public function getDecodedName()
    {
        return Transcoder::create()->transcode($this->name,"UTF7-IMAP");
    }

    /**
     * Get number of messages in this mailbox
     *
     * @return int
     */
    public function count()
    {
        $this->init();

        return imap_num_msg($this->connection->getResource());
    }

    public function getAttributes()
    {
        $attributes = array();
        $mask = $this->mailbox->attributes;
        if(($mask & LATT_NOINFERIORS) == LATT_NOINFERIORS){
            $attributes[] = 'noinferiors';
        }
        if(($mask & LATT_NOSELECT) == LATT_NOSELECT){
            $attributes[] = 'noselect';
        }
        if(($mask & LATT_MARKED) == LATT_MARKED){
            $attributes[] = 'marked';
        }
        if(($mask & LATT_UNMARKED) == LATT_UNMARKED){
            $attributes[] = 'unmarked';
        }
        return $attributes;
    }

    public function getStatus()
    {
        //this prevent errors Notice: Unknown: [NONEXISTENT] Unknown Mailbox: [Gmail] (now in authenticated state) (Failure) (errflg=2) in Unknown on line 0
        //http://docs.maildev.com/article/61-gmail-nonexistent-unknown-mailbox

        if(in_array('noselect',$this->getAttributes())){
            return array();
        }

        $this->init();


        $status = imap_status($this->connection->getResource(),$this->mailbox->name,SA_ALL);

        if($status){
            return (array) $status;
        }

        throw new Exception("Can not get mailbox status at '{$this->mailbox->name}'");
    }

    /**
     * Get message ids
     *
     * @param SearchExpression $search Search expression (optional)
     *
     * @return MessageIterator|Message[]
     */
    public function getMessages(SearchExpression $search = null)
    {
        $this->init();

        $query = ($search ? (string) $search : 'ALL');

        //var_dump($query);
        $messageNumbers = imap_search($this->connection->getResource(), $query, \SE_UID);
        if (false == $messageNumbers) {
            // imap_search can also return false
            $messageNumbers = array();
        }

        return new MessageIterator($this->connection->getResource(), $messageNumbers);
    }

    /**
     * Возвращает массив UID писем по критерию и сортировке
     *
     * @param int $sort SORTDATE | SORTARRIVAL |..
     * @return void
     **/
    public function getMessageNumbers(SearchExpression $search = null,$sort = \SORTARRIVAL,$reverse = false)
    {
        $this->init();

        $query = ($search ? (string) $search : 'ALL');

        $messageNumbers = imap_sort($this->connection->getResource(),$sort,(int)$reverse,\SE_UID | \SE_NOPREFETCH,$query);
        if (false == $messageNumbers) {
            // imap_search can also return false
            $messageNumbers = array();
        }

        return $messageNumbers;
    }

    /**
     * Get a message by message number
     *
     * @param int $number Message number
     *
     * @return Message
     */
    public function getMessage($number,$lazyLoad = false)
    {
        $this->init();

        return new Message($this->connection->getResource(), $number,$lazyLoad);
    }

    /**
     * Get messages in this mailbox
     *
     * @return MessageIterator
     */
    public function getIterator()
    {
        $this->init();

        return $this->getMessages();
    }

    /**
     * Delete this mailbox
     *
     */
    public function delete()
    {
        $this->connection->deleteMailbox($this);
    }

    /**
     * Delete all messages marked for deletion
     *
     * @return Mailbox
     */
    public function expunge()
    {
        $this->init();

        imap_expunge($this->connection->getResource());

        return $this;
    }

    /**
     * Add a message to the mailbox
     *
     * @param string $message
     *
     * @return boolean
     */
    public function addMessage($message)
    {
        $this->init();
        return imap_append($this->connection->getResource(), $this->mailbox->name, $message);
    }

    /**
     * If connection is not currently in this mailbox, switch it to this mailbox
     */
    private function init()
    {
        $check = imap_check($this->connection->getResource());
        if ($check === false || $check->Mailbox != $this->mailbox->name) {

            set_error_handler([$this,'errorHandler']);
            $this->setLastException(null);

            $result = imap_reopen($this->connection->getResource(), $this->mailbox->name);

            restore_error_handler();

            if(!$result){
                throw new MailboxOpenException($this->mailbox->name);
            }

            $ex = $this->getLastException();
            if($ex){
                throw $ex;
            }
        }

    }

    protected function getLastException()
    {
        return $this->lastException;
    }

    protected function setLastException(Exception $e = null)
    {
        $this->lastException = $e;
    }

    public function errorHandler ($nr, $error)
    {
        $this->lastException = new Exception($error);
        return  true;
    }
}
