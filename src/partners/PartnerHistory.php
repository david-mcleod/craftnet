<?php

namespace craftnet\partners;

use Craft;
use craft\base\Model;
use craft\db\Query;
use craftnet\db\Table;
use JsonSerializable;
use yii\helpers\ArrayHelper;

class PartnerHistory extends Model implements JsonSerializable
{
    protected static $table = Table::PARTNERHISTORY;

    public $id;
    public $partnerId;
    public $authorId;
    public $message;
    public $dateCreated;
    public $dateUpdated;
    public $uid;

    protected $db;

    /**
     * @var array A row from the `users` table
     */
    protected $author;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->db = Craft::$app->getDb();
    }

    public static function deleteById(int $id)
    {
        return Craft::$app->getDb()->createCommand()
            ->delete(self::$table, 'id=:id', [':id' => $id])
            ->execute();
    }

    /**
     * @param int $partnerId
     * @return array
     */
    public static function findByPartnerId(int $partnerId)
    {
        $rows = (new Query())
            ->select('*')
            ->from(self::$table)
            ->where(compact('partnerId'))
            ->orderBy('dateCreated DESC')
            ->all();

        // Eager load authors
        $users = [];
        $authorIds = array_filter(ArrayHelper::getColumn($rows, 'authorId'));
        if (count($authorIds) !== 0) {
            $users = (new Query())
                ->select('*')
                ->from('users')
                ->where(['id' => $authorIds])
                ->all();

            $users = ArrayHelper::index($users, 'id');
        }

        // Map to PartnerHistory models
        $models = array_map(function($row) use ($users) {
            $model = new self($row);

            if ($model->authorId && array_key_exists($model->authorId, $users)) {
                $model->setAuthor($users[$model->authorId]);
            }

            return $model;
        }, $rows);

        return $models;
    }

    /**
     * @param array $params
     * @return PartnerHistory|null
     */
    public static function firstOrNew(array $params)
    {
        $row = [];

        if ($params['id']) {
            $row = (new Query())
                ->select('*')
                ->from(self::$table)
                ->where(['id' => $params['id']])
                ->one();
        }

        $model = new self($row);
        $model->setAttributes($params, false);
        $model->loadAuthor();

        return $model;
    }

    /**
     * @return array
     */
    protected function defineRules(): array
    {
        return [
            [['partnerId', 'message'], 'required'],
        ];
    }

    /**
     * @return mixed
     */
    public function getAuthor()
    {
        return $this->author;
    }

    /**
     * @return string
     */
    public function getAuthorName()
    {
        if (!is_array($this->author)) {
            return '';
        }

        $fullName = trim((string)$this->author['firstName'] . ' ' . (string)$this->author['lastName']);

        return $fullName ?: $this->author['username'];
    }

    /**
     * @param array $user
     */
    public function setAuthor(array $user)
    {
        $this->author = $user;
    }

    /**
     * Loads the `user` record as `author`
     */
    public function loadAuthor()
    {
        $user = null;

        if ($this->authorId) {
            $user = (new Query())
                ->from('users')
                ->where(['id' => $this->authorId])
                ->one();
        }

        $this->setAuthor($user);
    }

    /**
     * Sets the `partnerId` attribute by Partner model
     *
     * @param Partner $partner
     */
    public function setPartner(Partner $partner)
    {
        $this->partnerId = $partner->id;
    }

    /**
     * @return bool
     * @throws \yii\db\Exception
     */
    public function save()
    {
        if (!$this->validate()) {
            return false;
        }

        if (!$this->id) {
            $data = $this->getAttributes(['partnerId', 'authorId', 'message']);

            $this->db->createCommand()
                ->insert(self::$table, $data)
                ->execute();

            $this->id = (int)$this->db->getLastInsertID();
        } else {
            $data = $this->getAttributes(['id', 'partnerId', 'authorId', 'message']);

            $this->db->createCommand()
                ->update(self::$table, $data, 'id=:id', [':id' => $data['id']], true)
                ->execute();
        }

        // Refresh
        $this->reload();

        return true;
    }

    /**
     * Refresh this model from the database if `id` is set.
     *
     * @return bool
     */
    public function reload()
    {
        if (!$this->id) {
            return false;
        }

        $model = self::firstOrNew(['id' => $this->id]);
        $this->setAttributes($model->getAttributes(), false);
        $this->loadAuthor();

        return true;
    }

    /**
     * @return int Rows affected by the delete query
     */
    public function delete()
    {
        return static::deleteById($this->id);
    }

    /**
     * Adds a couple of author values in case of use in templates and such.
     *
     * @param array $fields
     * @param array $expand
     * @param bool $recursive
     * @return array
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true)
    {
        $authorname = $this->getAuthorName();

        $extra = [
            'authorName' => $authorname,
            'authorUsername' => $authorname ? $this->getAuthor()['username'] : '',
        ];

        return array_merge($this->getAttributes(), $extra);
    }

    /**
     * Specify data which should be serialized to JSON
     *
     * @link http://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4.0
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
