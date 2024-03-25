<?php

/***** ***** ***** ***** *****
* Send a curl post request after each afterSurveyComplete event
*
* @originalauthor Stefan Verweij <stefan@evently.nl>
* @copyright 2016 Evently <https://www.evently.nl>
  @author IrishWolf
* @copyright 2023 Nerds Go Casual e.V.
* @license GPL v3
* @version 1.0.0
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
***** ***** ***** ***** *****/

class LimeSurveyWebhook extends PluginBase
	{
		protected $storage = 'DbStorage';
		static protected $description = 'A simple Webhook for LimeSurvey';
		static protected $name = 'LimeSurveyWebhook';
		protected $surveyId;

		public function init()
			{
				$this->subscribe('afterSurveyComplete'); // After Survey Complete
			}

		protected $settings = array(
			'sUrl' => array(
				'type' => 'string',
				'label' => 'The default URL to send the webhook to:',
				'help' => 'To test get one from https://webhook.site'
			),
			'sId' => array(
				'type' => 'string',
				'default' => '000000',
				'label' => 'The ID of the surveys:',
				'help' => 'The unique number of the surveys. You can set multiple surveys with an "," as separator. Example: 123456, 234567, 345678'
			),
      'sAuthToken' => array(
        'type' => 'string',
        'label' => 'API Authentication Token',
        'help' => 'Maybe you need a token to verify your request? <br> This will be send in plain text / not encoded!'
      ),
			'sBug' => array(
				'type' => 'select',
				'options' => array(
					0 => 'No',
					1 => 'Yes'
				),
				'default' => 0,
				'label' => 'Enable Debug Mode',
				'help' => 'Enable debugmode to see what data is transmitted. Respondents will see this as well so you should turn this off for live surveys'
			)
		);

		/***** ***** ***** ***** *****
		* Send the webhook on completion of a survey
		* @return array | response
		***** ***** ***** ***** *****/
		public function afterSurveyComplete()
			{
        $oEvent = $this->getEvent();
        $surveyId = $oEvent->get('surveyId');
        $hookSurveyId = $this->get('sId', null, null, $this->settings['sId']);
        $hookSurveyIdArray = explode(',', preg_replace('/\s+/', '', $hookSurveyId));
        if (in_array($surveyId, $hookSurveyIdArray))
                    {
                        $this->callWebhook('afterSurveyComplete');
                    }
				return;
			}

		/***** ***** ***** ***** *****
		* Calls a webhook
		* @return array | response
		***** ***** ***** ***** *****/
		private function callWebhook($comment)
			{
				$time_start = microtime(true);
				$event = $this->getEvent();
				$surveyId = $event->get('surveyId');
				$responseId = $event->get('responseId');
				$response = $this->pluginManager->getAPI()->getResponse($surveyId, $responseId);
				$submitDate = $response['submitdate'];
				$url = $this->get('sUrl', null, null, $this->settings['sUrl']);
                $hookSurveyId = $this->get('sId', null, null, $this->settings['sId']);
                $auth = $this->get('sAuthToken', null, null, $this->settings['sAuthToken']);
                $parameters = array(
                    "api_token" => $auth,
                    "survey" => $surveyId,
                    "event" => $comment,
                    "respondId" => $responseId,
                    "response" => json_encode($response),
                    "submitDate" => $submitDate,
                    "token" => isset($sToken) ? $sToken : null
                );
                $hookSent = $this->httpPost($url, $parameters);

                $this->log($comment . " | Params: ". json_encode($parameters) . json_encode($hookSent));
                $this->debug($url, $parameters, $hookSent, $time_start, $response);

                return;
            }

        private function getLastResponse($surveyId, $additionalFields)
            {
                if ($additionalFields)
                    {
                        $columnsInDB = \getQuestionInformation\helpers\surveyCodeHelper::getAllQuestions($surveyId);

                        $aadditionalSQGA = array();
                        foreach ($additionalFields as $field)
                            {
                                $push_val = array_search(trim($field), $columnsInDB);
                                if ($push_val) array_push($aadditionalSQGA, $push_val);
                            }
                        if (count($additionalFields) > 0)
                            {
                            $sadditionalSQGA = ", " . implode(', ', $aadditionalSQGA);
                            }
                    }

                $responseTable = $this->api->getResponseTable($surveyId);
                $query = "SELECT id, token, submitdate {$sadditionalSQGA} FROM {$responseTable} ORDER BY submitdate DESC LIMIT 1";
                $rawResult = Yii::app()->db->createCommand($query)->queryRow();

                $result = $rawResult;

                if (count($aadditionalSQGA) > 0)
                    {
                        foreach ($aadditionalSQGA as $SQGA)
                            {
                                $result[$columnsInDB[$SQGA]] = htmlspecialchars($rawResult[$SQGA]);
                                if ($push_val)
                                    array_push($aadditionalSQGA, $push_val);
                            }
                    }

                return $result;
            }

        /***** ***** ***** ***** *****
        * httpPost function http://hayageek.com/php-curl-post-get/
        * creates and executes a POST request
        * returns the output
        ***** ***** ***** ***** *****/
        private function httpPost($url, $params)
            {
                $postData = $params;
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_POST, count($postData));
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

                $output = curl_exec($ch);
                curl_close($ch);
                return $output;
            }

        /***** ***** ***** ***** *****
        * debugging
        ***** ***** ***** ***** *****/
        private function debug($url, $parameters, $hookSent, $time_start, $response)
            {
                if ($this->get('sBug', null, null, $this->settings['sBug']) == 1)
                  {
                    $this->log($comment);
                    $html = '<pre><br><br>----------------------------- DEBUG ----------------------------- <br><br>';
                    $html .= 'Parameters: <br>' . print_r($parameters, true);
                    $html .= "<br><br> ----------------------------- <br><br>";
                    $html .= 'Hook sent to: ' . print_r($url, true) . '<br>';
                    $html .= 'Total execution time in seconds: ' . (microtime(true) - $time_start);
                    $html .= '</pre>';
                    $event = $this->getEvent();
                    $event->getContent($this)->addContent($html);
                  }
		          }
    }
