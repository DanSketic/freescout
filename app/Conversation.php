<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Input;

class Conversation extends Model
{
    /**
     * By whom action performed (used in fields: source_via, last_reply_from).
     */
    const PERSON_CUSTOMER = 1;
    const PERSON_USER = 2;

    public static $persons = [
        self::PERSON_CUSTOMER => 'customer',
        self::PERSON_USER     => 'user',
    ];

    /**
     * Max length of the preview.
     */
    const PREVIEW_MAXLENGTH = 255;

    /**
     * Conversation types.
     */
    const TYPE_EMAIL = 1;
    const TYPE_PHONE = 2;
    const TYPE_CHAT = 3; // not used

    public static $types = [
        self::TYPE_EMAIL => 'email',
        self::TYPE_PHONE => 'phone',
        self::TYPE_CHAT  => 'chat',
    ];

    /**
     * Conversation statuses (code must be equal to thread statuses).
     */
    const STATUS_ACTIVE = 1;
    const STATUS_PENDING = 2;
    const STATUS_CLOSED = 3;
    const STATUS_SPAM = 4;
    // Present in the API, but what does it mean?
    const STATUS_OPEN = 5;

    public static $statuses = [
        self::STATUS_ACTIVE  => 'active',
        self::STATUS_PENDING => 'pending',
        self::STATUS_CLOSED  => 'closed',
        self::STATUS_SPAM    => 'spam',
        //self::STATUS_OPEN => 'open',
    ];

    /**
     * https://glyphicons.bootstrapcheatsheets.com/.
     */
    public static $status_icons = [
        self::STATUS_ACTIVE  => 'ok',
        self::STATUS_PENDING => 'hourglass',
        self::STATUS_CLOSED  => 'lock',
        self::STATUS_SPAM    => 'ban-circle',
        //self::STATUS_OPEN => 'folder-open',
    ];

    public static $status_classes = [
        self::STATUS_ACTIVE  => 'success',
        self::STATUS_PENDING => 'default',
        self::STATUS_CLOSED  => 'grey',
        self::STATUS_SPAM    => 'danger',
        //self::STATUS_OPEN => 'folder-open',
    ];

    public static $status_colors = [
        self::STATUS_ACTIVE  => '#71c171',
        self::STATUS_PENDING => '',
        self::STATUS_CLOSED  => '#87939f',
        self::STATUS_SPAM    => '#de6864',
    ];

    /**
     * Conversation states.
     */
    const STATE_DRAFT = 1;
    const STATE_PUBLISHED = 2;
    const STATE_DELETED = 3;

    public static $states = [
        self::STATE_DRAFT     => 'draft',
        self::STATE_PUBLISHED => 'published',
        self::STATE_DELETED   => 'deleted',
    ];

    /**
     * Source types (equal to thread source types).
     */
    const SOURCE_TYPE_EMAIL = 1;
    const SOURCE_TYPE_WEB = 2;
    const SOURCE_TYPE_API = 3;

    public static $source_types = [
        self::SOURCE_TYPE_EMAIL => 'email',
        self::SOURCE_TYPE_WEB   => 'web',
        self::SOURCE_TYPE_API   => 'api',
    ];

    /**
     * Automatically converted into Carbon dates.
     */
    protected $dates = ['created_at', 'updated_at', 'last_reply_at', 'closed_at'];

    /**
     * Attributes which are not fillable using fill() method.
     */
    protected $guarded = ['id', 'folder_id'];

    protected static function boot()
    {
        parent::boot();

        self::creating(function (Conversation $model) {
            $model->number = Conversation::where('mailbox_id', $model->mailbox_id)->max('number') + 1;
        });
    }

    /**
     * Who the conversation is assigned to (assignee).
     */
    public function user()
    {
        return $this->belongsTo('App\User');
    }

    /**
     * Get the folder to which conversation belongs.
     */
    public function folder()
    {
        return $this->belongsTo('App\Folder');
    }

    /**
     * Get the mailbox to which conversation belongs.
     */
    public function mailbox()
    {
        return $this->belongsTo('App\Mailbox');
    }

    /**
     * Get the customer associated with this conversation (primaryCustomer).
     */
    public function customer()
    {
        return $this->belongsTo('App\Customer');
    }

    /**
     * Get conversation threads.
     */
    public function threads()
    {
        return $this->hasMany('App\Thread');
    }

    /**
     * Folders containing starred conversations.
     */
    public function extraFolders()
    {
        return $this->belongsTo('App\Customer');
    }

    /**
     * Get user who created the conversations.
     */
    public function created_by_user()
    {
        return $this->belongsTo('App\User');
    }

    /**
     * Get customer who created the conversations.
     */
    public function created_by_customer()
    {
        return $this->belongsTo('App\Customer');
    }

    /**
     * Get user who closed the conversations.
     */
    public function closed_by_user()
    {
        return $this->belongsTo('App\User');
    }

    /**
     * Get only reply threads from conversations.
     *
     * @return Collection
     */
    public function getReplies()
    {
        return $this->threads()
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            ->where('state', Thread::STATE_PUBLISHED)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get all published conversation thread in desc order.
     *
     * @return Collection
     */
    public function getThreads($skip = null, $take = null)
    {
        $query = $this->threads()
            ->where('state', Thread::STATE_PUBLISHED)
            ->orderBy('created_at', 'desc');

        if (!is_null($skip)) {
            $query->skip($skip);
        }
        if (!is_null($take)) {
            $query->take($take);
        }

        return $query->get();
    }

    /**
     * Set preview text.
     *
     * @param string $text
     */
    public function setPreview($text = '')
    {
        $this->preview = '';

        if (!$text) {
            $first_thread = $this->threads()->first();
            if ($first_thread) {
                $text = $first_thread->body;
            }
        }

        // Remove all kinds of spaces after tags
        // https://stackoverflow.com/questions/3230623/filter-all-types-of-whitespace-in-php
        $text = preg_replace("/^(.*)>[\r\n]*\s+/mu", '$1>', $text);

        $text = strip_tags($text);
        $text = preg_replace('/\s+/mu', ' ', $text);

        // Trim
        $text = trim($text);
        $text = preg_replace('/^\s+/mu', '', $text);

        // Causes "General error: 1366 Incorrect string value"
        // Remove "undetectable" whitespaces
        // $whitespaces = ['%81', '%7F', '%C5%8D', '%8D', '%8F', '%C2%90', '%C2', '%90', '%9D', '%C2%A0', '%A0', '%C2%AD', '%AD', '%08', '%09', '%0A', '%0D'];
        // $text = urlencode($text);
        // foreach ($whitespaces as $char) {
        //     $text = str_replace($char, ' ', $text);
        // }
        // $text = urldecode($text);

        $text = trim(preg_replace('/[ ]+/', ' ', $text));

        $this->preview = mb_substr($text, 0, self::PREVIEW_MAXLENGTH);

        return $this->preview;
    }

    /**
     * Get conversation timestamp title.
     *
     * @return string
     */
    public function getDateTitle()
    {
        if ($this->threads_count == 1) {
            $title = __('Created by :person<br/>:date', ['person' => ucfirst(__(
            self::$persons[$this->source_via])), 'date' => User::dateFormat($this->created_at, 'M j, Y H:i')]);
        } else {
            $title = __('Last reply by :person<br/>:date', ['person' => ucfirst(__(self::$persons[$this->source_via])), 'date' => User::dateFormat($this->created_at, 'M j, Y H:i')]);
        }

        return $title;
    }

    public function isActive()
    {
        return $this->status == self::STATUS_ACTIVE;
    }

    /**
     * Get status name.
     *
     * @return string
     */
    public function getStatusName()
    {
        return self::statusCodeToName($this->status);
    }

    /**
     * Convert status code to name.
     *
     * @param int $status
     *
     * @return string
     */
    public static function statusCodeToName($status)
    {
        switch ($status) {
            case self::STATUS_ACTIVE:
                return __('Active');
                break;

            case self::STATUS_PENDING:
                return __('Pending');
                break;

            case self::STATUS_CLOSED:
                return __('Closed');
                break;

            case self::STATUS_SPAM:
                return __('Spam');
                break;

            case self::STATUS_OPEN:
                return __('Open');
                break;

            default:
                return '';
                break;
        }
    }

    /**
     * Set conersation status and all related fields.
     *
     * @param int $status
     */
    public function setStatus($status, $user = null)
    {
        $now = date('Y-m-d H:i:s');

        $this->status = $status;
        $this->updateFolder();
        $this->user_updated_at = $now;

        if ($user && $status == self::STATUS_CLOSED) {
            $this->closed_by_user_id = $user->id;
            $this->closed_at = $now;
        }
    }

    /**
     * Set conversation user and all related fields.
     *
     * @param int $user_id
     */
    public function setUser($user_id)
    {
        $now = date('Y-m-d H:i:s');

        if ($user_id == -1) {
            $user_id = null;
        }

        $this->user_id = $user_id;
        $this->updateFolder();
        $this->user_updated_at = $now;
    }

    /**
     * Get next active conversation.
     *
     * @param string $mode next|prev|closest
     *
     * @return Conversation
     */
    public function getNearby($mode = 'closest')
    {
        $folder = $this->folder;
        $query = self::where('folder_id', $folder->id)
            ->where('id', '<>', $this->id);
        $order_bys = $folder->getOrderByArray();

        if ($mode != 'prev') {
            // Try to get next conversation
            $query_next = $query;
            foreach ($order_bys as $order_by) {
                foreach ($order_by as $field => $sort_order) {
                    if ($sort_order == 'asc') {
                        $query_next->where($field, '>=', $this->$field);
                    } else {
                        $query_next->where($field, '<=', $this->$field);
                    }
                    $query_next->orderBy($field, $sort_order);
                }
            }
        }
        // echo 'folder_id'.$folder->id.'|';
        // echo 'id'.$this->id.'|';
        // echo 'status'.self::STATUS_ACTIVE.'|';
        // echo '$this->status'.$this->status.'|';
        // echo '$this->last_reply_at'.$this->last_reply_at.'|';
        // echo $query_next->toSql();
        // exit();
        $conversation = $query_next->first();

        if ($conversation || $mode == 'next') {
            return $conversation;
        }

        // Try to get previous conversation
        $query_prev = $query;
        foreach ($order_bys as $order_by) {
            foreach ($order_by as $field => $sort_order) {
                if ($sort_order == 'asc') {
                    $query_prev->where($field, '<=', $this->$field);
                } else {
                    $query_prev->where($field, '<=', $this->$field);
                }
                $query_prev->orderBy($field, $sort_order);
            }
        }

        return $query_prev->first();
    }

    /**
     * Set folder according to the status, state and user of the conversation.
     */
    public function updateFolder()
    {
        if ($this->state == self::STATE_DRAFT) {
            $folder_type = Folder::TYPE_DRAFTS;
        } elseif ($this->state == self::STATE_DELETED) {
            $folder_type = Folder::TYPE_DELETED;
        } elseif ($this->status == self::STATUS_SPAM) {
            $folder_type = Folder::TYPE_SPAM;
        } elseif ($this->status == self::STATUS_CLOSED) {
            $folder_type = Folder::TYPE_CLOSED;
        } elseif ($this->user_id) {
            $folder_type = Folder::TYPE_ASSIGNED;
        } else {
            $folder_type = Folder::TYPE_UNASSIGNED;
        }

        // Find folder
        $folder = $this->mailbox->folders()
            ->where('type', $folder_type)
            ->first();

        if ($folder) {
            $this->folder_id = $folder->id;
        }
    }

    /**
     * Set CC as JSON.
     */
    public function setCc($emails)
    {
        $emails_array = self::sanitizeEmails($emails);
        if ($emails_array) {
            $emails_array = array_unique($emails_array);
            $this->cc = json_encode($emails_array);
        } else {
            $this->cc = null;
        }
    }

    /**
     * Set BCC as JSON.
     */
    public function setBcc($emails)
    {
        $emails_array = self::sanitizeEmails($emails);
        if ($emails_array) {
            $emails_array = array_unique($emails_array);
            $this->bcc = json_encode($emails_array);
        } else {
            $this->bcc = null;
        }
    }

    /**
     * Get CC recipients.
     *
     * @return array
     */
    public function getCcArray()
    {
        if ($this->cc) {
            return json_decode($this->cc);
        } else {
            return [];
        }
    }

    /**
     * Get BCC recipients.
     *
     * @return array
     */
    public function getBccArray()
    {
        if ($this->bcc) {
            return json_decode($this->bcc);
        } else {
            return [];
        }
    }

    /**
     * Convert list of email to array.
     *
     * @return
     */
    public static function sanitizeEmails($emails)
    {
        $emails_array = [];

        if (is_array($emails)) {
            $emails_array = $emails;
        } else {
            $emails_array = explode(',', $emails);
        }

        foreach ($emails_array as $i => $email) {
            $emails_array[$i] = Email::sanitizeEmail($email);
            if (!$emails_array[$i]) {
                unset($emails_array[$i]);
            }
        }

        return $emails_array;
    }

    /**
     * Get conversation URL.
     *
     * @return string
     */
    public function url($folder_id = null)
    {
        $params = ['id' => $this->id];
        if (!$folder_id) {
            $folder_id = self::getCurrentFolder();
        }
        $params['folder_id'] = $folder_id;

        return route('conversations.view', $params);
    }

    /**
     * Get CSS color of the status.
     *
     * @return string
     */
    public function getStatusColor()
    {
        return self::$status_colors[$this->status];
    }

    /**
     * Get folder ID from request or use the default one.
     */
    public function getCurrentFolder($default_folder_id = null)
    {
        $folder_id = self::getFolderParam();
        if ($folder_id) {
            return $folder_id;
        }
        if ($this->folder_id) {
            return $this->folder_id;
        } else {
            return $default_folder_id;
        }
    }

    public static function getFolderParam()
    {
        if (!empty(request()->folder_id)) {
            return request()->folder_id;
        } elseif (!empty(Input::get('folder_id'))) {
            return Input::get('folder_id');
        }

        return '';
    }

    /**
     * Check if conversation can be in the folder.
     */
    public function isInFolderAllowed($folder)
    {
        if (in_array($folder->type, Folder::$public_types)) {
            return $folder->id == $this->folder_id;
        } elseif ($folder->type == Folder::TYPE_MINE) {
            $user = auth()->user();
            if ($user && $user->id == $folder->user_id && $this->user_id == $user->id) {
                return true;
            } else {
                return false;
            }
        } else {
            // todo: check ConversationFolder here
        }

        return false;
    }

    /**
     * Get text for the assignee.
     *
     * @return string
     */
    public function getAssigneeName($ucfirst = false, $user = null)
    {
        if (!$this->user_id) {
            if ($ucfirst) {
                return __('Anyone');
            } else {
                return __('anyone');
            }
        } elseif (($user && $this->user_id == $user->id) || (!$user && $this->user_id == auth()->user()->id)) {
            if ($ucfirst) {
                return __('Me');
            } else {
                return __('me');
            }
        } else {
            return $this->user->getFullName();
        }
    }
}