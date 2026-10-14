<?php

namespace Tests\Feature;

use App\Domain\Api\ApiCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_strona_dokumentacji_dziala_bez_logowania(): void
    {
        $this->get(route('api-docs'))
            ->assertOk()
            ->assertSee('Dokumentacja API')
            ->assertSee('/servers/{server}/power')
            ->assertSee('data-api-send', false)
            ->assertDontSee('/admin/hypervisors');
    }

    public function test_personel_widzi_endpointy_administracyjne(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get(route('api-docs'))->assertOk()->assertSee('/admin/hypervisors')->assertSee('/billing/services');

        $this->get(route('api-docs', ['all' => 1]))->assertSee('/admin/hypervisors');
    }

    public function test_dokumentacja_po_angielsku(): void
    {
        $this->get(route('api-docs', ['lang' => 'en']))->assertOk()->assertSee('API documentation')->assertSee('Try it');
    }

    public function test_openapi_jest_poprawny_w_kazdym_jezyku(): void
    {
        foreach (array_keys(config('virthub.locales')) as $lang) {
            $spec = $this->getJson(route('api-docs.openapi', ['lang' => $lang]))->assertOk()
                ->assertHeader('Access-Control-Allow-Origin', '*')->json();

            $this->assertSame('3.0.3', $spec['openapi']);
            $this->assertStringEndsWith('/api/v1', $spec['servers'][0]['url']);
            $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
            $operations = collect($spec['paths'])->flatMap(fn ($ops) => array_values($ops));
            $this->assertCount(count(ApiCatalog::endpoints()), $operations);
            $this->assertSame(count(ApiCatalog::endpoints()), $operations->pluck('operationId')->unique()->count());
            foreach ($spec['paths'] as $path => $ops) {
                preg_match_all('/\{(\w+)\}/', $path, $m);
                foreach ($ops as $op) {
                    $declared = collect($op['parameters'] ?? [])->where('in', 'path')->pluck('name')->all();
                    $this->assertEqualsCanonicalizing($m[1], $declared, $path);
                }
            }
        }

        $en = $this->getJson(route('api-docs.openapi', ['lang' => 'en']))->json();
        $this->assertSame('Current user', $en['paths']['/me']['get']['summary']);
    }

    public function test_kolekcja_postman(): void
    {
        $response = $this->get(route('api-docs.postman', ['lang' => 'en']))->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $collection = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('v2.1.0', $collection['info']['schema']);
        $this->assertSame(['baseUrl', 'token'], array_column($collection['variable'], 'key'));
        $this->assertSame('bearer', $collection['auth']['type']);
        $requests = collect($collection['item'])->flatMap(fn ($folder) => $folder['item']);
        $this->assertCount(count(ApiCatalog::endpoints()), $requests);
        $this->assertStringStartsWith('{{baseUrl}}', $requests->first()['request']['url']['raw']);
    }

    public function test_kazdy_opisany_endpoint_istnieje_w_api(): void
    {
        foreach (ApiCatalog::endpoints() as $endpoint) {
            $uri = preg_replace('/\{\w+\}/', '1', $endpoint['path']);
            try {
                $route = Route::getRoutes()->match(Request::create('/api/v1'.$uri, $endpoint['method']));
            } catch (NotFoundHttpException) {
                $this->fail("Brak trasy {$endpoint['method']} {$endpoint['path']}");
            }
            $this->assertStringStartsWith('api/v1', $route->uri(), $endpoint['id']);
        }
    }

    public function test_link_z_ustawien_tokenow(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('panel.account.security'))->assertOk()->assertSee(route('api-docs'));
    }
}
