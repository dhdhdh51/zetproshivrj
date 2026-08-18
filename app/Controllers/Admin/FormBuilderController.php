<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Settings;
use App\Services\Forms;

/**
 * The two form builders.
 *
 * The same controller serves the customer visit form (TYPE A, rendered in the
 * Android app) and the BC Supervisor inspection form (TYPE B, rendered on the
 * web), because both are the same structure in different tables. This is what
 * lets the final inspection questionnaire be decided later without touching code.
 */
final class FormBuilderController extends BaseController
{
    public function index(Request $request): void
    {
        $kind = $this->kind($request);
        $forms = Forms::forms($kind);
        $formId = (int) $request->query('form_id', 0);

        if ($formId === 0 && $forms !== []) {
            $formId = (int) $forms[0]['id'];
        }

        $form = $formId > 0 ? Forms::form($kind, $formId) : null;
        $fields = $form === null ? [] : Forms::fields($kind, $formId, false);

        $this->page('admin.forms.builder', [
            'title' => $kind === Forms::KIND_INSPECTION ? 'Inspection form builder' : 'Visit form builder',
            'kind' => $kind,
            'forms' => $forms,
            'form' => $form,
            'fields' => $fields,
            'fieldTypes' => Forms::fieldTypes(),
            'usageCounts' => $form === null ? [] : $this->usageCounts($kind, $formId),
            'editField' => $this->editField($kind, $formId, (int) $request->query('field_id', 0)),
        ]);
    }

    public function storeForm(Request $request): void
    {
        $kind = $this->kind($request);

        $this->validate($request, ['name' => 'required|max:160']);

        $formId = Forms::createForm($kind, $request->all());

        $this->success('Form created. Add its fields below.');
        $this->redirect('/admin/forms/' . $kind . '?form_id=' . $formId);
    }

    public function updateForm(Request $request): void
    {
        $kind = $this->kind($request);
        $formId = $request->paramInt('id');

        $this->validate($request, ['name' => 'required|max:160']);

        Forms::updateForm($kind, $formId, $request->all());

        $this->success('Form updated.');
        $this->redirect('/admin/forms/' . $kind . '?form_id=' . $formId);
    }

    public function duplicateForm(Request $request): void
    {
        $kind = $this->kind($request);
        $newId = Forms::duplicate($kind, $request->paramInt('id'));

        $this->success('Form duplicated as an inactive draft. Edit it, then activate it when ready.');
        $this->redirect('/admin/forms/' . $kind . '?form_id=' . $newId);
    }

    public function saveField(Request $request): void
    {
        $kind = $this->kind($request);
        $formId = $request->paramInt('id');
        $fieldId = (int) $request->input('field_id', 0);

        Forms::saveField($kind, $formId, $request->all(), $fieldId > 0 ? $fieldId : null);

        $this->success($fieldId > 0 ? 'Field updated.' : 'Field added.');
        $this->redirect('/admin/forms/' . $kind . '?form_id=' . $formId);
    }

    public function deleteField(Request $request): void
    {
        $kind = $this->kind($request);
        $formId = $request->paramInt('id');
        $fieldId = $request->paramInt('field');

        Forms::deleteField($kind, $formId, $fieldId);

        $this->success('Field removed. Fields with existing answers are deactivated instead of deleted so history stays readable.');
        $this->redirect('/admin/forms/' . $kind . '?form_id=' . $formId);
    }

    public function reorder(Request $request): void
    {
        $kind = $this->kind($request);
        $formId = $request->paramInt('id');
        $order = $request->raw('order');

        if (is_array($order)) {
            Forms::reorder($kind, $formId, array_map('intval', $order));
            $this->success('Field order saved.');
        }

        $this->redirect('/admin/forms/' . $kind . '?form_id=' . $formId);
    }

    /**
     * Make a form the one new visits/inspections will use.
     */
    public function setDefault(Request $request): void
    {
        $kind = $this->kind($request);
        $formId = $request->paramInt('id');
        $form = Forms::form($kind, $formId);

        Forms::updateForm($kind, $formId, [
            'name' => $form['name'],
            'description' => $form['description'],
            'visit_type' => $form['visit_type'] ?? 'customer',
            'is_active' => 1,
            'is_default' => 1,
        ]);

        if ($kind === Forms::KIND_VISIT) {
            Settings::set('default_visit_form_id', (string) $formId, 'forms');
        }

        $this->success('"' . $form['name'] . '" will now be used for new records.');
        $this->redirect('/admin/forms/' . $kind . '?form_id=' . $formId);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function kind(Request $request): string
    {
        $kind = (string) $request->param('kind', Forms::KIND_VISIT);

        if (!in_array($kind, [Forms::KIND_VISIT, Forms::KIND_INSPECTION], true)) {
            $this->abort(404, 'Unknown form type.');
        }

        return $kind;
    }

    /**
     * How many submitted answers each field already has — a field with history
     * cannot be deleted outright.
     *
     * @return array<int, int>
     */
    private function usageCounts(string $kind, int $formId): array
    {
        $tables = Forms::tables($kind);

        $rows = Database::select(
            sprintf(
                'SELECT v.field_id, COUNT(*) AS total
                   FROM `%s` v
                   JOIN `%s` f ON f.id = v.field_id
                  WHERE f.form_id = :form
                  GROUP BY v.field_id',
                $tables['values'],
                $tables['fields']
            ),
            ['form' => $formId]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['field_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function editField(string $kind, int $formId, int $fieldId): ?array
    {
        if ($fieldId <= 0 || $formId <= 0) {
            return null;
        }

        $tables = Forms::tables($kind);

        $field = Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE id = :id AND form_id = :form', $tables['fields']),
            ['id' => $fieldId, 'form' => $formId]
        );

        return $field;
    }
}
