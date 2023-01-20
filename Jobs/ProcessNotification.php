<?php
 
namespace Modules\Requestable\Jobs;
 
 use Illuminate\Bus\Queueable;

 use Illuminate\Contracts\Queue\ShouldQueue;
 use Illuminate\Foundation\Bus\Dispatchable;
 use Illuminate\Queue\InteractsWithQueue;
 use Illuminate\Queue\SerializesModels;

 use Modules\Requestable\Entities\Requestable;
 
class ProcessNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
 

    private $rule;
    private $requestable;
    private $lastHistoryStatusId;

    public $notificationService;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($rule,$requestable,$lastHistoryStatusId)
    {
        
        $this->rule = $rule;
        $this->requestable = $requestable;
        $this->lastHistoryStatusId = $lastHistoryStatusId;

        $this->notificationService = app("Modules\Notification\Services\Inotification");
    }

    /**
     * Handle init
    */
    public function handle(){

        $catRule = $this->rule->categoryRule;
        \Log::info('Requestable: Jobs|ProcessNotification|AutomationRuleID:'.$this->rule->id.' Category:'.$catRule->system_name.' ============');

        $ruleFields = count($this->rule->fields)>0 ? $this->rule->fields : null;
        //\Log::info('Requestable: Jobs|ProcessNotification|RuleFields: '.$ruleFields);

        //Requestable Old
        $requestableData = $this->requestable;
        //Requestable Now
        $requestableNow = Requestable::find($requestableData->id);

        //There may be saved jobs but the current requestable does not have the same status
        //There may be saved jobs with the same status but not with the same information
        //If the status history ids are the same then continue..
        if($requestableNow->lastStatusHistoryId()==$this->lastHistoryStatusId){
          
            // Only if automation rule has fields (from fillables)
            if(!is_null($ruleFields)){

                $requestableFields = count($requestableData->fields)>0 ? $requestableData->fields : null;
                //\Log::info('Requestable: Jobs|ProcessNotification|RequestableFields: '.$requestableFields);

                // Only if requestable has fields (from fillables)
                if(!is_null($requestableFields)){

                    //Params to execute notifications
                    $params = [
                        "ruleFields" => $ruleFields,
                        "requestableFields" => $requestableFields,
                        "toFieldName" => $this->rule->to,
                        "requestableData" => $requestableData
                    ];

                    // Depending on the category - Execute notifications
                    if($catRule->system_name=="send-email")
                        $this->sendEmail($params);
                    
                    if($catRule->system_name=="send-whatsapp")
                        $this->sendMobile($params,"whatsapp"); 
                    
                    if($catRule=="send-sms")
                        $this->sendMobile($params,"sms");
                    
                    if($catRule->system_name=="send-telegram")
                        $this->sendMobile($params,"telegram");

                }

            }else{
                \Log::info('Requestable: Jobs|ProcessNotification|AutomationRuleID:'.$this->rule->id.' - Not fields (fillables) for this Rule ');
            }

        }else{
            \Log::info('Requestable: Jobs|ProcessNotification|Not same ids from Status History');
        }

        
    }

    /**
    * Notification Send Email
    * @param $ruleFields
    * @param $requestableFields
    * @param $toFieldName (from attribute 'to' saved in the Automation Rule )
    */
    public function sendEmail(array $params){
        
        // Fillables from AutomationRule
        $from = $this->getValueField('from',$params['ruleFields']) ?? null;
        $subject = $this->getValueField('subject',$params['ruleFields']) ?? "Subject Test";
        $message = $this->getValueField('message',$params['ruleFields']) ?? "Message Test";
        \Log::info('Requestable: Jobs|ProcessNotification|sendEmail|FROM: '.$from.' - SUBJECT: '.$subject.' - MESSAGE: '.$message);

        // Fillables from Requestable
        $emailsTo[] = $this->getValueField($params['toFieldName'],$params['requestableFields']);
        \Log::info('Requestable: Jobs|ProcessNotification|sendEmail|emailsTo: '.json_encode($emailsTo));

        //Check Variables to replace
        $subject = $this->checkVariables($subject,$params['requestableFields']);
        $message = $this->checkVariables($message,$params['requestableFields']);
        
        $this->notificationService->to([
            "email" => $emailsTo
        ])->push([
            "title" => $subject,
            "message" => $message,
            "fromAddress" => $from,
            "fromName" => "",
            "setting" => [
                "saveInDatabase" => true
            ]
        ]);
                
    }


    /**
    * Notification Send Mobile
    * @param $ruleFields
    * @param $requestableFields
    * @param $toFieldName (from attribute 'to' saved in the Automation Rule )
    * @param $type (whatsapp,telegram,sms)
    */
    public function sendMobile(array $params,string $type){

        // Fillables from AutomationRule
        $message = $this->getValueField('message',$params['ruleFields']) ?? "Message Test";
        \Log::info('Requestable: Jobs|ProcessNotification|sendMobile|message: '.$message);
           
        //Fillables from Requestable
        $sendTo = $this->getValueField($params['toFieldName'],$params['requestableFields']);
        \Log::info('Requestable: Jobs|ProcessNotification|sendMobile|sendTo: '.json_encode($sendTo));
        
        //Check Variables to replace
        $message = $this->checkVariables($message,$params['requestableFields']);

        $messageToSend = [
            "message" => $message,
            "provider" => $type,
            "recipient_id" => $sendTo,
            "sender_id" => $params['requestableData']->requestedBy->id,
            "send_to_provider" => true
        ];
        //\Log::info('Requestable: Jobs|ProcessNotification|sendMobile|messageToSend: '.json_encode($messageToSend));

        //Message service from Ichat Module
        if (is_module_enabled('Ichat')) {
            $messageService = app("Modules\Ichat\Services\MessageService");
            $messageService->create($messageToSend);
        }
            
    }

    
    /**
    * Get Value from specific field from Fillables Fields
    */
    public function getValueField(string $name, object $fields){

        $value = null;
        $valueInforField = $fields->firstWhere('name',$name);

        //\Log::info('Requestable: Jobs|ProcessNotification|getValueField|value: '.$valueInforField);

        if(!is_null($valueInforField) && !empty($valueInforField))
            $value = $valueInforField->translations->first()->value;
    
        return $value;

    }

    /**
    * @param $str (text which can contain variables)
    */
    public function checkVariables($str,$requestableFields){
        
        if (preg_match_all("~\{\{\s*(.*?)\s*\}\}~", $str, $matches)){
            \Log::info('Requestable: Jobs|ProcessNotification|checkVariables|Matches: '.json_encode($matches[1]));
            
            foreach ($matches[1] as $key => $match) {
                \Log::info('Requestable: CheckVariables|Search this value: '.$match);

                $value = $this->getValueField($match,$requestableFields) ?? "--";
                \Log::info('Requestable: CheckVariables|Value: '.$value);

                //Ready to replace
                $find = "{{".$match."}}";
                $str = str_replace($find,$value,$str);
            }

            \Log::info('Requestable: Jobs|ProcessNotification|checkVariables|Str: '.$str);
        }
        
        return $str;
    }


}