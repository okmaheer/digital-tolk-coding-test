<?php
namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $userType = '';
        $emergencyJobs = array();
        $normalJobs = array();
        if ($cuser && $cuser->is('customer')) {
            $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')->whereIn('status', ['pending', 'assigned', 'started'])->orderBy('due', 'asc')->get();
            $userType = 'customer';
        } elseif ($cuser && $cuser->is('translator')) {
            $jobs = Job::getTranslatorJobs($cuser->id, 'new');
            $jobs = $jobs->pluck('jobs')->all();
            $userType = 'translator';
        }
        if ($jobs) {
            foreach ($jobs as $jobitem) {
                if ($jobitem->immediate == 'yes') {
                    $emergencyJobs[] = $jobitem;
                } else {
                    $normalJobs[] = $jobitem;
                }
            }
            $normalJobs = collect($normalJobs)->each(function ($item, $key) use ($user_id) {
                $item['usercheck'] = Job::checkParticularJob($user_id, $item);
            })->sortBy('due')->all();
        }

        return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'cuser' => $cuser, 'usertype' => $userType];
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page');
        $pageNum = (isset($page)) ? $page : 1;
        $cuser = User::find($user_id);
        $userType = '';
        $emergencyJobs = array();
        $normalJobs = array();
        $jobs = array();

        if ($cuser && $cuser->is('customer')) {
            $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])->orderBy('due', 'desc')->paginate(15);
            $response = ['emergencyJobs' => $emergencyJobs, 'normalJobs' => [], 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => 'customer', 'numpages' => 0, 'pagenum' => 0];
        } elseif ($cuser && $cuser->is('translator')) {
            $jobsIds = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pageNum);
            $response = ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $jobIds, 'jobs' => $jobIds, 'cuser' => $cuser, 'usertype' => 'translator', 'numpages' => ceil($jobsIds->total() / 15), 'pagenum' => $pageNum];
        } else {
            $response = ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $userType, 'numpages' => 0, 'pagenum' => 0];
        }
        return $response;
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $immediateTime = 5;
        $consumerType = $user->userMeta->consumer_type;
        if ($user->user_type == env('CUSTOMER_ROLE_ID')) {
            $cuser = $user;

            if (!isset($data['from_language_id'])) {
                $response['status'] = 'fail';
                $response['message'] = "Du måste fylla in alla fält";
                $response['field_name'] = "from_language_id";
                return $response;
            }
            if ($data['immediate'] == 'no') {
                if (isset($data['due_date']) && $data['due_date'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_date";
                    return $response;
                }
                if (isset($data['due_time']) && $data['due_time'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_time";
                    return $response;
                }
                if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste göra ett val här";
                    $response['field_name'] = "customer_phone_type";
                    return $response;
                }
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";
                    return $response;
                }
            } else {
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";
                    return $response;
                }
            }
            if (isset($data['customer_phone_type'])) {
                $data['customer_phone_type'] = 'yes';
            } else {
                $data['customer_phone_type'] = 'no';
            }

            if (isset($data['customer_physical_type'])) {
                $data['customer_physical_type'] = 'yes';
                $response['customer_physical_type'] = 'yes';
            } else {
                $data['customer_physical_type'] = 'no';
                $response['customer_physical_type'] = 'no';
            }

            if ($data['immediate'] == 'yes') {
                $due_carbon = Carbon::now()->addMinute($immediateTime);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';
            } else {
                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type'] = 'regular';
                $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                if ($due_carbon->isPast()) {
                    $response['status'] = 'fail';
                    $response['message'] = "Can't create booking in past";
                    return $response;
                }
            }
            if (in_array('male', $data['job_for'])) {
                $data['gender'] = 'male';
            } elseif (in_array('female', $data['job_for'])) {
                $data['gender'] = 'female';
            }
            if (in_array('normal', $data['job_for'])) {
                $data['certified'] = 'normal';
            } elseif (in_array('certified', $data['job_for'])) {
                $data['certified'] = 'yes';
            } elseif (in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'law';
            } elseif (in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'health';
            }
            if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
                $data['certified'] = 'both';
            } elseif(in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'n_law';
            } elseif(in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'n_health';
            }

            if ($consumerType == 'rwsconsumer')
                $data['job_type'] = 'rws';
            elseif ($consumerType == 'ngo')
                $data['job_type'] = 'unpaid';
            elseif ($consumerType == 'paid')
                $data['job_type'] = 'paid';
            $data['b_created_at'] = date('Y-m-d H:i:s');
            if (isset($due))
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
            $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';
            $job = $cuser->jobs()->create($data);
            $response['status'] = 'success';
            $response['id'] = $job->id;
            $data['job_for'] = array();
            if ($job->gender != null) {
                if ($job->gender == 'male') {
                    $data['job_for'][] = 'Man';
                } elseif ($job->gender == 'female') {
                    $data['job_for'][] = 'Kvinna';
                }
            }
            if ($job->certified != null) {
                if ($job->certified == 'both') {
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                } elseif ($job->certified == 'yes') {
                    $data['job_for'][] = 'certified';
                } else {
                    $data['job_for'][] = $job->certified;
                }
            }

            $data['customer_town'] = $cuser->userMeta->city;
            $data['customer_type'] = $cuser->userMeta->customer_type;

            //Event::fire(new JobWasCreated($job, $data, '*'));
        } else {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";
        }

        return $response;

    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail($data['user_email_job_id']);
        $job->user_email = $data['user_email'];
        $job->reference = isset($data['reference']) ? $data['reference'] : '';
        $user = $job->user()->get()->first();
        if (isset($data['address'])) {
            $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
            $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
        }
        $job->save();

        if (!empty($job->user_email)) {
            $email = $job->user_email;
            $name = $user->name;
        } else {
            $email = $user->email;
            $name = $user->name;
        }

        $send_data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id, 'emails.job-created', $send_data);

        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['status'] = 'success';
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));
        return $response;
    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {
        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        list($dueDate, $dueTime) = explode(" ", $job->due);
        $data['due_date'] = $dueDate;
        $data['due_time'] = $dueTime;
        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } elseif ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            switch ($job->certified) {
                case 'both':
                    $data['job_for'][] = 'Godkänd tolk';
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'yes':
                    $data['job_for'][] = 'Auktoriserad'
                    break;
                case 'n_health':
                    $data['job_for'][] = 'Sjukvårdstolk';
                    break;
                case 'law' || 'n_law':
                    $data['job_for'][] = 'Rätttstolk';
                    break;
                default:
                    $data['job_for'][] = $job->certified
                    break;
            }
        }

        return $data;
    }

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = array())
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $post_data["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);
        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $jobDetail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;
        $user = $job->user()->get()->first();
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send((!empty($job->user_email)) ? $job->user_email : $user->email, $user->name, 'Information om avslutad tolkning för bokningsnummer # ' . $job->id, 'emails.session-ended', $data);

        $job->save();
        $tr = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
        Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($user->email, $user->name, 'Information om avslutad tolkning för bokningsnummer # ' . $job->id, 'emails.session-ended', $data);

        $tr->completed_at = $completedDate;
        $tr->completed_by = $post_data['userid'];
        $tr->save();
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $userMeta = UserMeta::where('user_id', $user_id)->first();
        $translatorType = $userMeta->translator_type;
        $jobType = 'unpaid';

        if ($translatorType == 'professional')
            $jobType = 'paid';   /*show all jobs for professionals.*/
        elseif ($translatorType == 'rwstranslator')
            $jobType = 'rws';  /* for rwstranslator only show rws jobs. */
        elseif ($translatorType == 'volunteer')
            $jobType = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $user_id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $userMeta->gender;
        $translatorLevel = $userMeta->translator_level;
        $jobIds = Job::getJobs($user_id, $jobType, 'pending', $userlanguage, $gender, $translatorLevel);

        foreach ($jobIds as $k => $v)     // checking translator town
        {
            $job = Job::find($v->id);
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($jobIds[$k]);
            }
        }
        $jobs = TeHelper::convertJobIdsInObjs($jobIds);
        return $jobs;
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::all();
        $translator_array = array();            // suitable translators (no need to delay push)
        $delpay_translator_array = array();     // suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $exclude_user_id) { // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($oneUser->id)) continue;
                $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') continue;
                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) { // one potential job is the same with current job
                        $userId = $oneUser->id;
                        $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($job_for_translator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob($userId, $oneJob);
                            if (($job_checker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delpay_translator_array[] = $oneUser;
                                } else {
                                    $translator_array[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = '';
        if ($data['immediate'] == 'no') {
            $msg_contents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } else {
            $msg_contents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }
        $msg_text = array(
            "en" => $msg_contents
        );

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);
        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        // analyse weather it's phone or physical; if both = default to phone
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } elseif ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        } elseif ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
            // It's both, but should be handled as phone job
            $message = $phoneJobMessageTemplate;
        } else {
            // This shouldn't be feasible, so no handling of this edge case
            $message = '';
        }
        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) return false;
        return (TeHelper::getUsermeta($user_id, 'not_get_nighttime') == 'yes') ? true : false;
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        return (TeHelper::getUsermeta($user_id, 'not_get_notification') == 'yes') ? false : true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);
        if (env('APP_ENV') == 'prod') {
            $onesignalAppID = config('app.prodOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
        } else {
            $onesignalAppID = config('app.devOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        }

        $userTags = $this->getUserTagsStringFromArray($users);
        $data['job_id'] = $job_id;
        $iosSound = $androidSound =' default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $androidSound = 'normal_booking';
                $iosSound = 'normal_booking.mp3';
            } else {
                $androidSound = 'emergency_booking';
                $iosSound = 'emergency_booking.mp3';
            }
        }

        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($userTags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $androidSound,
            'ios_sound'      => $iosSound
        );
        if ($is_need_delay) {
            $nextBusinessTime = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $nextBusinessTime;
        }
        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {

        $jobType = $job->job_type;

        if ($jobType == 'paid')
            $translatorType = 'professional';
        elseif ($jobType == 'rws')
            $translatorType = 'rwstranslator';
        elseif ($jobType == 'unpaid')
            $translatorType = 'volunteer';

        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translatorLevel = [];

        if (!empty($job->certified)) {
            switch ($job->certified) {
                case 'yes' || 'both':
                    $translatorLevel[] = 'Certified';
                    $translatorLevel[] = 'Certified with specialisation in law';
                    $translatorLevel[] = 'Certified with specialisation in health care';
                    break;
                case 'law' || 'n_law':
                    $translatorLevel[] = 'Certified with specialisation in law';
                    break;
                case 'health' || 'n_health':
                    $translatorLevel[] = 'Certified with specialisation in health care';
                    break;
                case 'normal' || 'both':
                    $translatorLevel[] = 'Certified with specialisation in health care';
                    break;
                case null:
                    $translatorLevel[] = 'Certified';
                    $translatorLevel[] = 'Certified with specialisation in law';
                    $translatorLevel[] = 'Certified with specialisation in health care';
                    $translatorLevel[] = 'Layman';
                    $translatorLevel[] = 'Read Translation courses';
                    break;
            }
        }

        $blackList = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blackList)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translatorType, $joblanguage, $gender, $translatorLevel, $translatorsId);

        // foreach ($jobIds as $k => $v)     // checking translator town
        // {
        //     $job = Job::find($v->id);
        //     $jobuserid = $job->user_id;
        //     $checktown = Job::checkTowns($jobuserid, $user_id);
        //     if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
        //         unset($jobIds[$k]);
        //     }
        // }
        // $jobs = TeHelper::convertJobIdsInObjs($jobIds);
        return $users;
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);

        $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($current_translator)) $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

        $logData = [];
        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) $logData[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $logData[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $logData[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) $logData[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];
        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $logData);
        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();
            if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $old_time);
            if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);          return null;
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changed_translator
     * @return array
     */
    private function changeStatus($job, $data, $changed_translator)
    {
        $old_status = $job->status;
        $statusChanged = false;
        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changed_translator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changed_translator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $logData = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $logData];
            }
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changed_translator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changed_translator)
    {
        // if (in_array($data['status'], ['pending', 'assigned']) && date('Y-m-d H:i:s') <= $job->due) {
        $old_status = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);
            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);
            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators
            return true;
        } elseif ($changed_translator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        // }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        // if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout'])) {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
        // }
        // return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        // if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'completed'])) {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['sesion_time'] == '') return false;
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];
            $this->mailer->send((!empty($job->user_email)) ? $job->user_email :  $user->email, $user->name, 'Information om avslutad tolkning för bokningsnummer #' . $job->id, 'emails.session-ended', $dataEmail);
            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];
            $this->mailer->send($user->user->email, $user->user->name, 'Information om avslutad tolkning för bokningsnummer # ' . $job->id, 'emails.session-ended', $dataEmail);

        }
        $job->save();
        return true;
        // }
        // return false;
    }

    /**
     * @param $job
     * @param $data
     * @param $changed_translator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changed_translator)
    {
        // if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'assigned'])) {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'assigned' && $changed_translator) {
            $job->save();
            $job_data = $this->jobToData($job);
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }
        // }
        // return false;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $dueExplode = explode(' ', $due);

        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $dueExplode[1] . ' på ' . $dueExplode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $dueExplode[1] . ' på ' . $dueExplode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];
                $this->mailer->send((!empty($job->user_email)) ? $job->user_email : $user->email, $user->name, 'Information om avslutad tolkning för bokningsnummer #' . $job->id, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];
                $this->mailer->send($user->user->email, $user->user->name, 'Information om avslutad tolkning för bokningsnummer # ' . $job->id, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $logData = [];

            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $logData[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $logData[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged) return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $logData];
        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        if ($old_due != $new_due) {
            $logData = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            return ['dateChanged' => true, 'log_data' => $logData];
        }

        return ['dateChanged' => false];
    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($current_translator) {
            $user = $current_translator->user;
            $data['user'] = $user;
            $this->mailer->send($user->email, $user->name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $data['user'] = $user;
        $this->mailer->send($user->email, $user->name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send((!empty($job->user_email)) ? $job->user_email : $user->email, $user->name, 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send((!empty($job->user_email)) ? $job->user_email : $user->email, $user->name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $userMeta = $job->user->userMeta()->first();
        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $userMeta->city;
        $data['customer_type'] = $userMeta->customer_type;

        list($dueDate, $dueTime) = explode(" ", $job->due);
        $data['due_date'] = $dueDate;
        $data['due_time'] = $dueTime;
        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } elseif ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } elseif ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );
        else
            $msg_text = array(
                "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $userTags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $userTags .= ',{"operator": "OR"},';
            }
            $userTags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $userTags .= ']';

        return $userTags;
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                } else {
                    $email = $user->email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                }
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

            }
            /*@todo
                add flash message here.
            */
            $jobs = $this->getPotentialJobs($cuser);
            $response = array();
            $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;
    }

    /**
     * @param $job_id
     * @param $cuser
     */
    public function acceptJobWithId($job_id, $cuser)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);
        $response = array();

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send((!empty($job->user_email)) ? $job->user_email : $user->email, $user->name, $subject, 'emails.job-accepted', $data);

                $data = array();
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                );
                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = array($user);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                }
                // Your Booking is accepted sucessfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }
        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = array();
        /*@todo
            add 24hrs loging here.
            If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancelation is within 24
            if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $cuser = $user;
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } else {
                $job->status = 'withdrawafter24';
                $response['jobstatus'] = 'success';
            }
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
            if ($translator) {
                $data = array();
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                );
                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = array($translator);
                    $this->sendPushNotificationToSpecificUsers($users_array, $jobId, $data, $msg_text, $this->isNeedToDelayPush($translator->id));// send Session Cancel Push to Translaotor
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->get()->first();
                if ($customer) {
                    $data = array();
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = array(
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    );
                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = array($customer);
                        $this->sendPushNotificationToSpecificUsers($users_array, $jobId, $data, $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                    }
                }
                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();
                // Event::fire(new JobWasCanceled($job));
                Job::deleteTranslatorJobRel($translator->id, $jobId);
                $data = $this->jobToData($job);
                $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }
        return $response;
    }

    /**
     * @param $cuser
     */
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $jobType = 'unpaid';
        $translatorType = $cuser_meta->translator_type;
        if ($translatorType == 'professional')
            $jobType = 'paid';   /*show all jobs for professionals.*/
        elseif ($translatorType == 'rwstranslator')
            $jobType = 'rws';  /* for rwstranslator only show rws jobs. */
        elseif ($translatorType == 'volunteer')
            $jobType = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translatorLevel = $cuser_meta->translator_level;
        // Call the town function for checking if the job physical, then translators in one town can get job
        $jobIds = Job::getJobs($cuser->id, $jobType, 'pending', $userlanguage, $gender, $translatorLevel);
        foreach ($jobIds as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob') unset($jobIds[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) unset($jobIds[$k]);
        }
        // $jobs = TeHelper::convertJobIdsInObjs($jobIds);
        return $jobIds;
    }

    public function endJob($post_data)
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $post_data["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);

        if($jobDetail->status != 'started') return ['status' => 'success'];

        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $jobDetail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send((!empty($job->user_email)) ? $job->user_email : $user->email, $user->name, 'Information om avslutad tolkning för bokningsnummer # ' . $job->id, 'emails.session-ended', $data);

        $job->save();
        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($user->email, $user->name, 'Information om avslutad tolkning för bokningsnummer # ' . $job->id, 'emails.session-ended', $data);

        $tr->completed_at = $completedDate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    /**
     * @param $post_data
     */
    public function customerNotCall($post_data)
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $post_data["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);
        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $jobDetail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';
        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completedDate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestData = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumerType = $cuser->consumer_type;

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $allJobs = Job::query();

            if (isset($requestData['feedback']) && $requestData['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($requestData['count']) && $requestData['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($requestData['id']) && $requestData['id'] != '') {
                if (is_array($requestData['id']))
                    $allJobs->whereIn('id', $requestData['id']);
                else
                    $allJobs->where('id', $requestData['id']);
                $requestData = array_only($requestData, ['id']);
            }

            if (isset($requestData['lang']) && $requestData['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestData['lang']);
            }
            if (isset($requestData['status']) && $requestData['status'] != '') {
                $allJobs->whereIn('status', $requestData['status']);
            }
            if (isset($requestData['expired_at']) && $requestData['expired_at'] != '') {
                $allJobs->where('expired_at', '>=', $requestData['expired_at']);
            }
            if (isset($requestData['will_expire_at']) && $requestData['will_expire_at'] != '') {
                $allJobs->where('will_expire_at', '>=', $requestData['will_expire_at']);
            }
            if (isset($requestData['customer_email']) && count($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $users = User::whereIn('email', $requestData['customer_email'])->get();
                if ($users) {
                    $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            }
            if (isset($requestData['translator_email']) && count($requestData['translator_email'])) {
                $users = User::whereIn('email', $requestData['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('created_at', '>=', $requestData["from"]);
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('due', '>=', $requestData["from"]);
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }

            if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestData['job_type']);
                /*$allJobs->where('jobs.job_type', '=', $requestData['job_type']);*/
            }

            if (isset($requestData['physical'])) {
                $allJobs->where('customer_physical_type', $requestData['physical']);
                $allJobs->where('ignore_physical', 0);
            }

            if (isset($requestData['phone'])) {
                $allJobs->where('customer_phone_type', $requestData['phone']);
                if(isset($requestData['physical']))
                $allJobs->where('ignore_physical_phone', 0);
            }

            if (isset($requestData['flagged'])) {
                $allJobs->where('flagged', $requestData['flagged']);
                $allJobs->where('ignore_flagged', 0);
            }

            if (isset($requestData['distance']) && $requestData['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            if(isset($requestData['salary']) &&  $requestData['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }

            if (isset($requestData['count']) && $requestData['count'] == 'true') {
                $allJobs = $allJobs->count();
                return ['count' => $allJobs];
            }

            if (isset($requestData['consumer_type']) && $requestData['consumer_type'] != '') {
                $allJobs->whereHas('user.userMeta', function($q) use ($requestData) {
                    $q->where('consumer_type', $requestData['consumer_type']);
                });
            }

            if (isset($requestData['booking_type'])) {
                if ($requestData['booking_type'] == 'physical')
                    $allJobs->where('customer_physical_type', 'yes');
                if ($requestData['booking_type'] == 'phone')
                    $allJobs->where('customer_phone_type', 'yes');
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            $allJobs = ($limit == 'all') ? $allJobs->get() : $allJobs->paginate(15);
        } else {
            $allJobs = Job::query();

            if (isset($requestData['id']) && $requestData['id'] != '') {
                $allJobs->where('id', $requestData['id']);
                $requestData = array_only($requestData, ['id']);
            }

            ($consumerType == 'RWS') ? $allJobs->where('job_type', '=', 'rws') : $allJobs->where('job_type', '=', 'unpaid');

            if (isset($requestData['feedback']) && $requestData['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function($q) {
                    $q->where('rating', '<=', '3');
                });
                if(isset($requestData['count']) && $requestData['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($requestData['lang']) && $requestData['lang'] != '') $allJobs->whereIn('from_language_id', $requestData['lang']);
            if (isset($requestData['status']) && $requestData['status'] != '') $allJobs->whereIn('status', $requestData['status']);
            if (isset($requestData['job_type']) && $requestData['job_type'] != '') $allJobs->whereIn('job_type', $requestData['job_type']);

            if (isset($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $user = User::where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('user_id', '=', $user->id);
                }
            }

            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
                if (isset($requestData['from']) && $requestData['from'] != "") $allJobs->where('created_at', '>=', $requestData["from"]);
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
                if (isset($requestData['from']) && $requestData['from'] != "") $allJobs->where('due', '>=', $requestData["from"]);
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            if ($limit == 'all')
                $allJobs = $allJobs->get();
            else
                $allJobs = $allJobs->paginate(15);

        }
        return $allJobs;
    }

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs [$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId[] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = Request::all();
        $allCustomers = User::where('user_type', '1')->lists('email');
        $allTranslators = User::where('user_type', '2')->lists('email');
        $cuser = Auth::user();
        $consumerType = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (isset($requestData['lang']) && $requestData['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestData['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestData['lang']);*/
            }
            if (isset($requestData['status']) && $requestData['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestData['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestData['status']);*/
            }
            if (isset($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $user = User::where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestData['translator_email']) && $requestData['translator_email'] != '') {
                $user = User::where('email', $requestData['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestData["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestData["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestData['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $requestData['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $allCustomers, 'all_translators' => $allTranslators, 'requestdata' => $requestData];
    }

    public function userLoginFailed()
    {
        return ['throttles' => Throttles::where('ignore', 0)->with('user')->paginate(15)];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = Request::all();
        $allCustomers = User::where('user_type', '1')->lists('email');
        $allTranslators = User::where('user_type', '2')->lists('email');
        $cuser = Auth::user();
        $consumerType = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);

            if (isset($requestData['lang']) && $requestData['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestData['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                // $allJobs->where('jobs.from_language_id', '=', $requestData['lang']);
            }

            if (isset($requestData['status']) && $requestData['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestData['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                // $allJobs->where('jobs.status', '=', $requestData['status']);
            }

            if (isset($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $user = User::where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }

            if (isset($requestData['translator_email']) && $requestData['translator_email'] != '') {
                $user = User::where('email', $requestData['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }

            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestData["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }

            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestData["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestData['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                // $allJobs->where('jobs.job_type', '=', $requestData['job_type']);
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);

        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $allCustomers, 'all_translators' => $allTranslators, 'requestdata' => $requestData];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobId = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobId);
        $job = $job->toArray();

        $data = array();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $userid;
        $data['job_id'] = $jobId;
        $data['cancel_at'] = Carbon::now();

        $dataReopen = array();
        $dataReopen['status'] = 'pending';
        $dataReopen['created_at'] = Carbon::now();
        $dataReopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $dataReopen['created_at']);
        // $dataReopen['updated_at'] = date('Y-m-d H:i:s');
        // $this->logger->addInfo('USER #' . Auth::user()->id . ' reopen booking #: ' . $jobId);

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $jobId)->update($dataReopen);
            $newJobId = $jobId;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobId;
            // $job[0]['user_email'] = $user_email;
            $affectedRow = Job::create($job);
            $newJobId = $affectedRow['id'];
        }

        // $result = DB::table('translator_job_rel')->insertGetId($data);
        Translator::where('job_id', $jobId)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        Translator::create($data);
        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($newJobId);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time
     * @param  string $format
     * @return string
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } elseif ($time == 60) {
            return '1h';
        }

        return sprintf($format, floor($time / 60), $time % 60);
    }
}
