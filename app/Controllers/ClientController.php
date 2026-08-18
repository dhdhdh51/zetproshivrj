<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Validators\DocumentRules;

final class ClientController extends Controller
{
    private Client $clients;

    public function __construct()
    {
        $this->clients = new Client();
    }

    public function index(Request $request): void
    {
        $userId = (int) Auth::id();
        $search = (string) $request->query('q', '');

        $this->view('clients.index', [
            'title' => 'Clients',
            'search' => $search,
            'clients' => $this->clients->paginateForUser($userId, $search, $request->integer('page', 1), 12),
        ]);
    }

    public function create(Request $request): void
    {
        $this->view('clients.form', [
            'title' => 'Add client',
            'client' => null,
            'return_to' => (string) $request->query('return_to', ''),
        ]);
    }

    public function store(Request $request): void
    {
        $userId = (int) Auth::id();
        $data = $this->rules($request, '/clients/create');

        $data['user_id'] = $userId;
        $clientId = $this->clients->create($data);

        ActivityLog::record($userId, 'client.created', (string) $data['name'], 'client', $clientId);
        $this->success('Client added.');

        $returnTo = (string) $request->input('return_to', '');

        if ($returnTo === 'document') {
            $this->redirect('/documents/create?client_id=' . $clientId);

            return;
        }

        $this->redirect('/clients/' . $clientId);
    }

    public function show(Request $request): void
    {
        $userId = (int) Auth::id();
        $client = $this->clients->findForUser($request->paramInt('id'), $userId);

        $this->view('clients.show', [
            'title' => (string) $client['name'],
            'client' => $client,
            'documents' => $this->clients->documentsFor((int) $client['id'], 20),
        ]);
    }

    public function edit(Request $request): void
    {
        $client = $this->clients->findForUser($request->paramInt('id'), (int) Auth::id());

        $this->view('clients.form', [
            'title' => 'Edit ' . (string) $client['name'],
            'client' => $client,
            'return_to' => '',
        ]);
    }

    public function update(Request $request): void
    {
        $userId = (int) Auth::id();
        $client = $this->clients->findForUser($request->paramInt('id'), $userId);

        $data = $this->rules($request, '/clients/' . (int) $client['id'] . '/edit');
        $this->clients->updateById((int) $client['id'], $data);

        ActivityLog::record($userId, 'client.updated', (string) $data['name'], 'client', (int) $client['id']);
        $this->success('Client updated.');
        $this->redirect('/clients/' . (int) $client['id']);
    }

    public function destroy(Request $request): void
    {
        $userId = (int) Auth::id();
        $client = $this->clients->findForUser($request->paramInt('id'), $userId);

        $this->clients->deleteById((int) $client['id']);
        ActivityLog::record($userId, 'client.deleted', (string) $client['name'], 'client', (int) $client['id']);

        $this->success('Client deleted. Their documents were kept.');
        $this->redirect('/clients');
    }

    /**
     * JSON lookup used by the document wizard.
     */
    public function search(Request $request): void
    {
        $userId = (int) Auth::id();
        $term = (string) $request->query('q', '');

        $clients = $term === ''
            ? $this->clients->forUser($userId)
            : $this->clients->search($userId, $term, 20);

        $this->json([
            'success' => true,
            'clients' => array_map(static fn (array $client): array => [
                'id' => (int) $client['id'],
                'name' => (string) $client['name'],
                'company' => (string) ($client['company'] ?? ''),
                'email' => (string) ($client['email'] ?? ''),
                'phone' => (string) ($client['phone'] ?? ''),
                'address' => (string) ($client['address'] ?? ''),
            ], $clients),
        ]);
    }

    /**
     * Create a client from the document wizard modal (JSON).
     */
    public function quickStore(Request $request): void
    {
        $userId = (int) Auth::id();

        $data = $this->validate($request, [
            'name' => 'required|min:2|max:160',
            'company' => 'nullable|max:160',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|max:40',
            'address' => 'nullable|max:1000',
        ]);

        $data['user_id'] = $userId;
        $clientId = $this->clients->create($data);
        ActivityLog::record($userId, 'client.created', (string) $data['name'], 'client', $clientId);

        $client = $this->clients->find($clientId) ?? [];

        $this->json([
            'success' => true,
            'message' => 'Client added.',
            'client' => [
                'id' => $clientId,
                'name' => (string) ($client['name'] ?? ''),
                'company' => (string) ($client['company'] ?? ''),
                'email' => (string) ($client['email'] ?? ''),
                'phone' => (string) ($client['phone'] ?? ''),
                'address' => (string) ($client['address'] ?? ''),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request, string $redirectTo): array
    {
        return $this->validate($request, DocumentRules::client(), [], $redirectTo);
    }
}
