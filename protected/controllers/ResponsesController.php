<?php
namespace ls\controllers;
use ls\models\forms\FormattingOptions;
use ls\models\Survey;
use Zend\Diactoros\Response;
use Zend\Diactoros\ServerRequest;

/**
 * This class will handle survey creation and manipulation.
 */
class ResponsesController extends Controller
{
    public $layout = 'survey';

    public function accessRules()
    {

        return array_merge([
            [
                'allow',
                'actions' => ['index'],
                'roles' => [
                    'responses' => ['crud' => 'read', 'entity' => 'survey', 'entity_id' => $this->getActionParams()['id']]
                ]

            ]
        ], parent::accessRules());
    }


    public function actionIndex($id) {
        /**
         * @todo Add permission check.
         */
        if (!\ls\models\Response::valid($id)) {
            throw new \CHttpException(404, gT("Response table not found"));
        }

        $survey = Survey::model()->findByPk($id);
        $this->menus['survey'] = $survey;

        $dataProvider = new \CActiveDataProvider(\ls\models\Response::model($id), [
            'pagination' => [
                'pageSize' => 20
            ]
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider, 'survey' => $survey]);
    }

    public function actionDelete($id, $surveyId) {
        // CSRF is enabled.
        // We allow POST and DELETE requests (since TbButtonColumn uses POST by default).
        /**
         * @todo Add permission check.
         */
        if (App()->request->isPostRequest || App()->request->isDeleteRequest) {
            return \ls\models\Response::model($surveyId)->deleteByPk($id);
        }

        $this->redirect(['responses/index', 'id' => $surveyId]);
    }

    /**
     * @todo Add permission check.
     */

    public function actionDeleteMultiple(array $ids, $surveyId) {
        if (App()->request->isDeleteRequest
            && 0 < $count = \ls\models\Response::model($surveyId)->deleteAllByAttributes(['id' => $ids]) ) {
            App()->user->setFlash('success', gT("Responses deleted"));
        } else {
            App()->user->setFlash('danger', gT("Responses not deleted"));
        }
        $this->redirect(['responses/index', 'id' => $surveyId]);
    }

    public function actionExport($id)
    {
        /* @var \ls\models\Survey $survey */
        $this->menus['survey'] = $survey = Survey::model()->findByPk($id);

        $options = new FormattingOptions();
        $options->surveyId = $survey->sid;

        /**
         * Use PSR-7 request for easier future migrations.
         */
        $psr7 = App()->request->psr7;
        if (strcasecmp($psr7->getMethod(), 'post') == 0
            && ($options->setAttributes($psr7->getParsedBody()[\Html::modelName($options)]) || true) // SetAttributes returns null so we use || true.
            && $options->validate()
        ) {
            /**
             * Write returns a stream, we can optionally pass a stream to force it to use that stream instead.
             * This would allow us to have it write directly to the browser.
             */
            $writerClass = $options->getWriter();
            /** @var \IWriter $writer */
            $writer = new $writerClass($options);
            $survey->language = App()->language;

            $headers = [
                'Content-Type' => $writer->getMimeType(),
                'Content-Disposition' => "inline; filename='{$writer->getFileName($survey, App()->language)}'"
            ];
            $response = new Response($writer->write($survey, App()->language), 200, $headers);

            (new Response\SapiEmitter())->emit($response);
            // Disable weblogroutes.
            foreach(App()->log->routes as $route) {
                if ($route instanceof \CWebLogRoute) {
                    $route->enabled = false;
                }
            }

            return;
        }
        $this->render('export', ['options' => $options]);
    }

    public function actionView($id, $surveyId)
    {
        $response = \ls\models\Response::model($surveyId)->findByPk($id);
        $this->menus['survey'] = $response->survey;
        return $this->render('view', [
            'response' => $response
        ]);
    }

    /**
     * This function appends a new response to the series of the response id given.
     * If the current series_id is set to null it's initialized to 0.
     *
     * @param int $surveyId
     * @param string $id
     * @param bool $copy
     */
    public function actionAppend($surveyId, $id, $copy = false)
    {
        $response = \ls\models\Response::model($surveyId)->findByPk($id);
        $newResponse = $response->append($copy);
        $newResponse->markAsUnFinished();
        $newResponse->save();

        $this->redirect(['responses/update', 'id' => $newResponse->getId(), 'surveyId' => $surveyId]);
    }
}