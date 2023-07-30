<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>CSP Simulator</title>
        @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    </head>
    <body class="index">
        <form action="{{ route('apply') }}" method="post" target="proxy" data-api="{{ url('/api/fetch') }}">
            @csrf
            <div class="container mt-3">
                <div class="d-flex">
                    <div class="flex-grow-1">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <div class="input-group-text">URL</div>
                            </div>
                            <input type="text" class="form-control" name="url" placeholder="https://example.org/">
                        </div>
                    </div>
                    <div class="ms-auto">
                        <button type="button" class="btn btn-secondary" id="fetch">Fetch</button>
                    </div>
                </div>
                <div class="d-flex align-items-end">
                    <div class="form-group flex-grow-1">
                        <label for="csp">Content-Security-Policy</label>
                        <textarea class="form-control" id="csp" name="csp" rows="5"></textarea>
                    </div>
                    <div class="ms-auto">
                        <button type="submit" class="btn btn-primary" id="apply">Apply</button>
                    </div>
                </div>
            </div>
        </form>
        <iframe src="{{ url('/empty') }}" name="proxy" class="mt-3 border-top border-bottom"></iframe>
    </body>
</html>
