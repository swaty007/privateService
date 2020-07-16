<?php

namespace app\controllers;

use app\models\ChatMessage;
use app\models\ChatSession;
use app\models\ChatUser;
use app\models\Crypt;
use app\models\Record;
use Yii;
use yii\filters\AccessControl;
use yii\helpers\Url;
use yii\web\Controller;
use yii\web\Response;
use yii\filters\VerbFilter;
use app\models\LoginForm;
use app\models\ContactForm;


class SiteController extends Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'only' => ['logout'],
                'rules' => [
                    [
                        'actions' => ['logout'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'logout' => ['post'],
                ],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
            'captcha' => [
                'class' => 'yii\captcha\CaptchaAction',
                'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
            ],
        ];
    }

    /**
     * Displays homepage.
     *
     * @return string
     */
    public function actionIndex()
    {
        return $this->render('index');
    }
    public function actionCheck()
    {
        foreach (Record::find()->all() AS $item)
        {
            $item->checkDate();
        }
        foreach (ChatSession::find()->all() AS $item)
        {
            $item->checkDate();
        }
        foreach (ChatUser::find()->all() AS $item)
        {
            $item->checkDate();
        }
        foreach (ChatMessage::find()->all() AS $item)
        {
            $item->checkDate();
        }
        return 'ok';
    }

    public function beforeAction($action)
    {
        Yii::$app->controller->enableCsrfValidation = false;

        return parent::beforeAction($action); // TODO: Change the autogenerated stub
    }


    public function actionChat()
    {
        return $this->render('chat');
    }

    public function actionNewChat()
    {
        $p = (string)Yii::$app->request->post('c_pass','');

        if(mb_strlen($p) > 30)
        {
            return $this->redirect('/');
        }

        $c = new ChatSession();

        $c->password = $p;
        $c->date_created_ts = time();
        $c->last_message_ts = time();

        do{
            $c->link = Yii::$app->security->generateRandomString(16);
        }while(!$c->save());

        return $this->redirect(['site/show','k' => 'c-'.$c->link, 'c' => $p]);
    }

    public function actionNewRecord()
    {
        if(Yii::$app->request->isAjax)
        {
            Yii::$app->response->format = 'json';


            $data = (string)Yii::$app->request->post('content','');
            $pass = (string)Yii::$app->request->post('pass','');
            $email = (string)Yii::$app->request->post('email',null);
            $mode = (string)Yii::$app->request->post('mode','');
            $base64 = (string)Yii::$app->request->post('base64','');


            if(mb_strlen($data) > 3000 || mb_strlen($pass) > 50 || mb_strlen($email) > 100 || mb_strlen($mode) > 10)
            {
                return ['result' => 'error'];
            }

            $r = new Record();

//            $r->content = Crypt::enc($data);
            $r->content = $data;
            $r->code  = $pass;
            $r->notify_email = $email;
            $r->mode  = $mode;
            $r->date_created = time();
            $r->base64_code = $base64;

            if(!$r->save())
            {
                return ['result' => 'error'];
            }

            if($r->validate() && $r->save() && $r->prepare())
            {
                return ['result' => 'success', 'link' => Url::to(['site/show', 'k' => 'r-'.$r->link]), 'original_key' => 'r-'.$r->link, 'rec_id' => $r->id];
            } else {
                return ['result' => [$r->validate() ,$r->save() ,$r->prepare()]];
            }
        }
    }


    public function actionUploadImage()
    {
        if(Yii::$app->request->isAjax)
        {
            Yii::$app->response->format = 'json';

            $id = Yii::$app->request->post('id',null);

            $r = Record::find()->where(['id' => $id])->andWhere(['image' => null]);

            if(!$r->count())
            {
                return ['result' => 1];
            }

            $r = $r->one();

            $info = pathinfo($_FILES['image']['name']);
            $ext = $info['extension']; // get the extension of the file
            $newname = time().".".$ext;

            $target = Yii::getAlias('@webroot') . '/images/'.$newname;

            if(move_uploaded_file( $_FILES['image']['tmp_name'], $target))
            {
                $r->image = '/images/'.$newname;
                $r->save();

                return ['result' => true];
            }

            return ['result' => 2];
        }
    }

    public function actionDelete ()
    {
        if(Yii::$app->request->isAjax)
        {
            Yii::$app->response->format = 'json';
            $id = Yii::$app->request->post('id');
            $r = Record::find()->where(['id' => $id])->andWhere(['mode' => 0]);
            if(!$r->count()) {
                return ['result' => 'false_delete'];
            }

            $r = $r->one();
            @unlink(Yii::getAlias('@webroot') .$r->image);

            if ($r->notify_email) {
                Yii::$app->mailer->compose()
                    ->setFrom('info@infinitum.tech')
                    ->setTo($r->notify_email)
                    ->setSubject('Сообщение успешно удаленно')
                    ->setHtmlBody("<p>Ваще сообщение с ключем <b>show?k=$r->link</b> было успешно просмотренно и удаленно</p>")
                    ->send();
            }
            $r->delete();
        }

    }

    public function actionShow()
    {
        $key = (string)Yii::$app->request->get('k','');
        $code = (string)Yii::$app->request->get('c','');

        if($key{1} != '-')
        {
            return $this->redirect('/');
        }

        $mode = $key{0};

        $key = substr($key,2);

        switch ($mode)
        {
            case 'r':
                $r = Record::find()->where(['link' => $key]);

                if($r->count()) {
                    $r = $r->one();

                    if($r->code !== '' && $r->code !== $code)
                    {
                        if($code == '')
                        {
                            return $this->render('need-code',['k' => $key,'c' => $code, 'fail_enter'=>false]);
                        }

                        return $this->render('need-code',['k' => $key,'c' => $code, 'fail_enter'=>true]);
                    }

                    if($r->checkDate())
                    {
                        return $this->render('show',['item' => $r]);
                    } else {
                        return $this->redirect('/');
                    }
                } else {
                    return $this->redirect('/');
                }
                break;

            case 'c':
                $c = ChatSession::find()->where(['link' => $key]);

                if($c->count()) {
                    $c = $c->one();

                    if($c->password !== '' && $c->password !== $code)
                    {
                        if($code == '')
                        {
                            return $this->render('need-code',['k' => $key,'c' => $code, 'fail_enter'=>false]);
                        }

                        return $this->render('need-code',['k' => $key,'c' => $code, 'fail_enter'=>true]);
                    }

                    $u = new ChatUser();

                    $u->chat_session = $c->id;
                    $u->last_message = time();

                    $u->token = Yii::$app->security->generateRandomString(64);

                    do{
                        $u->user_code = Yii::$app->security->generateRandomString(16);
                    }while(!$u->save());

                    $user_data = ['token' => $u->token, 'code' => $u->user_code];

                    $user_array = [];
                    foreach(ChatUser::find()->where(['chat_session' => $c->id])->all() AS $user)
                    {
                        $user_array[] = $user->user_code;
                    }

                    return $this->render('chat', ['chat' => $c->link, 'pass' => $code, 'user_data' => $user_data, 'users' => $user_array]);

                } else {
                    return $this->redirect('/');
                }
                break;
        }
    }

    public function actionSendMessage()
    {
        if(Yii::$app->request->isAjax)
        {
            $input = Yii::$app->request->post();

            $c = ChatSession::find()
                ->where(['AND',['link' => $input['chat']],['OR',['password' => ''],['password' => $input['pass']]]]);

            if(!$c->count())
            {
                return ['result' => 'false'];
            }

            $c = $c->one();
            $c->last_message_ts = time();
            $c->save();

            $u = ChatUser::find()->where(['AND',['token' => $input['token']],['user_code' => $input['user']],['chat_session' => $c->id]]);

            if(!$u->count())
            {
                return ['result' => 'false'];
            }

            $u = $u->one();
            $u->last_message = time();
            $u->save();

            $cm = ChatMessage::find()->where(['and',['chat_user' => $u->id],['chat_session' => $c->id],['>','date_created',(time()-2)]])->orderBy('id');

            if($cm->count())
            {
                return ['result' => 'false'];
            }

            $m = new ChatMessage();

            $m->chat_user = $u->id;
            $m->username = $input['user'];
            $m->chat_session= $c->id;
            $m->date_created= time();
            $m->text = htmlspecialchars($input['text']);

            $m->save();
        }
    }

    public function actionGetMessages()
    {
        if(Yii::$app->request->isAjax)
        {
            Yii::$app->response->format = 'json';

            $input = Yii::$app->request->post();

            $c = ChatSession::find()
                ->where(['AND',['link' => $input['chat']],['OR',['password' => ''],['password' => $input['pass']]]]);

            if(!$c->count())
            {
                return ['result' => '1'];
            }

            $c = $c->one();

            $u = ChatUser::find()->where(['AND',['token' => $input['token']],['user_code' => $input['code']],['chat_session' => $c->id]]);

            if(!$u->count())
            {
                return ['result' => '2'];
            }

            $user_array = [];
            foreach(ChatUser::find()->where(['chat_session' => $c->id])->all() AS $user)
            {
                $user_array[] = $user->user_code;
            }

            $cms = ChatMessage::find()->select('id,text,username')->where(['chat_session' => $c->id])->andWhere(['>','id',$input['last']])->orderBy('id ASC')->asArray()->all();
            return ['messages' => $cms , 'users' => $user_array];
        }
    }

    public function actionSendCurrencies2()
    {
        $data = json_decode(file_get_contents('https://blockchain.info/ticker'),true);

        $value = $data['USD']['buy'];

        $to = "Alex <swaty0007@gmail.com>";

        $subj = "$value$ for 1 BTC at ".date('d.m.Y',time());

        $message = "<html>BTC price checker <b><3</b>     <br><i>$subj</i></html>";

        $headers= "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=iso-8859-1\r\n";
        $headers .= "From: Infinitum TECH <info@infinitum.tech>\r\n";

        mail($to, $subj, $message, $headers);


        
        $this->actionSendCurrencies($data);
        exit;
    }

    private function actionSendCurrencies($data)
    {
//        $data = json_decode(file_get_contents('https://blockchain.info/ticker'),true);

        $value = $data['USD']['buy'];

        $to = "NJC <njcom3@gmail.com>,";

        $subj = "$value$ for 1 BTC at ".date('d.m.Y',time());

        $message = "<html>BTC price checker <b><3</b>";

        $headers= "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=iso-8859-1\r\n";
        $headers .= "From: Infinitum TECH <info@infinitum.tech>\r\n";

        mail($to, $subj, $message, $headers);
    }

    public function actionResendEmail ()
    {
        $data = Yii::$app->request->post();
        $hidden = $data['hidden'];
        $name = $data['name'];
        $lastName = $data['lastName'];
        $email = $data['email'];
        $body = $data['body'];
        $company = $data['company'];
        $MC = $data['MC'];
        $telephone = $data['telephone'];
            /* получатели */
            //           $to = Yii::$app->params['adminEmail'];
            $to = "Admin <info@senscologistics.com>, " ; //обратите внимание на запятую
            $to .= "Alex <swaty0007@gmail.com>";

            /* тема/subject */
            $subject = "New subject";

            /* сообщение */
            $message = "
<html>
<head>
 <title>Moves on site</title>
</head>
<body>
<p><strong>User send message from: $hidden</strong> </p>
<h2>Info about user</h2>
<p><strong>First Name: </strong>$name </p>
<p><strong>Last Name: </strong>$lastName </p>
<p><strong>email: </strong>$email </p>
<p><strong>body: </strong>$body </p>
<p><strong>Company: </strong>$company </p>
<p><strong>MC: </strong>$MC </p>
<p><strong>telephone: </strong>$telephone </p>
</body>
</html>
";

            /* Для отправки HTML-почты вы можете установить шапку Content-type. */
            $headers= "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=iso-8859-1\r\n";
            /* дополнительные шапки */
            $headers .= "From: "."Sensco Logistic".'.info'."<"."info.senscologistics.tech".">\r\n";
//            $headers .= "Cc: swaty0007@gmail.com\r\n"; //копия
            mail($to, $subject, $message, $headers);

            return ['result' => 'success' ];

        return ['result' => 'error' ];
    }
}