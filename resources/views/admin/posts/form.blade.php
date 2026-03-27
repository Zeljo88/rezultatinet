@extends('admin.layout')

@section('title', $post->exists ? 'Uredi post' : 'Novi post')

@section('content')
{{-- SimpleMDE CSS --}}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simplemde/dist/simplemde.min.css">

<div class="flex items-center gap-3 mb-6">
    <a href="{{ route('admin.posts.index') }}" class="text-gray-400 hover:text-gray-600 transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
    </a>
    <h1 class="text-2xl font-bold text-gray-800">
        {{ $post->exists ? 'Uredi post' : 'Novi post' }}
    </h1>
</div>

@if($errors->any())
<div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm">
    <ul class="list-disc list-inside space-y-1">
        @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

<form method="POST"
      action="{{ $post->exists ? route('admin.posts.update', $post->id) : route('admin.posts.store') }}"
      enctype="multipart/form-data"
      id="postForm">
    @csrf
    @if($post->exists) @method('PUT') @endif

    {{-- Hidden field for published state --}}
    <input type="hidden" name="published" id="published_input" value="{{ old('published', $post->published) ? '1' : '0' }}">

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- ===== MAIN COLUMN ===== --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Title + Slug --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                <label class="block text-sm font-semibold text-gray-700 mb-2" for="title">Naslov *</label>
                <input type="text"
                       name="title"
                       id="title"
                       value="{{ old('title', $post->title) }}"
                       placeholder="Unesite naslov posta..."
                       class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       required>

                <label class="block text-sm font-semibold text-gray-700 mt-4 mb-2" for="slug">Slug *</label>
                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-400 whitespace-nowrap">/blog/</span>
                    <input type="text"
                           name="slug"
                           id="slug"
                           value="{{ old('slug', $post->slug) }}"
                           class="flex-1 border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent font-mono"
                           required>
                </div>
            </div>

            {{-- Content — SimpleMDE --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                <label class="block text-sm font-semibold text-gray-700 mb-2" for="content">Sadržaj *</label>
                <textarea name="content" id="content" rows="16">{{ old('content', $post->content) }}</textarea>
            </div>

        </div>

        {{-- ===== SIDEBAR COLUMN ===== --}}
        <div class="space-y-6">

            {{-- Publish / Draft buttons --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                <h3 class="font-semibold text-gray-700 mb-4">Objava</h3>

                {{-- Visual toggle --}}
                <div class="flex items-center gap-3 mb-5 cursor-pointer" id="toggle-wrap">
                    <div class="relative">
                        <div id="toggle-bg" class="w-11 h-6 rounded-full transition-colors {{ old('published', $post->published) ? 'bg-blue-600' : 'bg-gray-300' }}"></div>
                        <div id="toggle-dot" class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform {{ old('published', $post->published) ? 'translate-x-5' : '' }}"></div>
                    </div>
                    <span id="toggle-label-text" class="text-sm font-medium text-gray-700">
                        {{ old('published', $post->published) ? 'Objavljeno' : 'Nacrt' }}
                    </span>
                </div>

                <div class="flex flex-col gap-2">
                    <button type="button"
                            id="btn-publish"
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg px-4 py-2.5 text-sm transition">
                        {{ $post->exists ? 'Spremi izmjene' : 'Objavi post' }}
                    </button>
                    <button type="button"
                            id="btn-draft"
                            class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-lg px-4 py-2.5 text-sm transition">
                        Spremi kao nacrt
                    </button>
                    <a href="{{ route('admin.posts.index') }}"
                       class="w-full text-center text-gray-500 hover:text-gray-700 text-sm py-2 transition">
                        Odustani
                    </a>
                </div>
            </div>

            {{-- Featured Image --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                <h3 class="font-semibold text-gray-700 mb-3">Naslovna slika</h3>

                {{-- Label wraps entire upload area — clicking opens file picker --}}
                <label for="featured_image_input" class="block cursor-pointer">
                    <div id="upload-area"
                         class="border-2 border-dashed border-gray-300 rounded-lg p-5 text-center transition hover:border-blue-400 hover:bg-blue-50">

                        {{-- Preview (existing from DB or new selection) --}}
                        @if($post->featured_image)
                        <div id="preview-wrapper" class="mb-3">
                            <img id="preview-img"
                                 src="{{ asset($post->featured_image) }}"
                                 class="max-h-40 mx-auto rounded-lg object-cover"
                                 alt="Featured">
                        </div>
                        @else
                        <div id="preview-wrapper" class="hidden mb-3">
                            <img id="preview-img" src="" class="max-h-40 mx-auto rounded-lg object-cover" alt="Preview">
                        </div>
                        @endif

                        <svg id="upload-icon"
                             class="w-10 h-10 mx-auto text-gray-400 mb-2 {{ $post->featured_image ? 'hidden' : '' }}"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>

                        <p id="upload-text" class="text-sm text-gray-500">
                            @if($post->featured_image)
                                Kliknite za promjenu slike
                            @else
                                Kliknite ili <strong>prevucite sliku ovdje</strong>
                            @endif
                        </p>
                        <p class="text-xs text-gray-400 mt-1">JPG, PNG, WebP — max 2MB</p>

                        <span class="mt-3 inline-block bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-4 py-2 rounded-lg transition">
                            Odaberi sliku
                        </span>
                    </div>
                </label>

                {{-- Hidden input — wired to label above --}}
                <input type="file"
                       id="featured_image_input"
                       name="featured_image"
                       class="hidden"
                       accept="image/*">
            </div>

            {{-- SEO --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                <h3 class="font-semibold text-gray-700 mb-4">SEO</h3>

                <div class="mb-4">
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="text-sm font-medium text-gray-700" for="meta_title">Meta naslov</label>
                        <span id="meta_title_count" class="text-xs text-gray-400">0/60</span>
                    </div>
                    <input type="text"
                           name="meta_title"
                           id="meta_title"
                           value="{{ old('meta_title', $post->meta_title) }}"
                           maxlength="60"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           placeholder="Meta naslov (do 60 znakova)">
                </div>

                <div class="mb-4">
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="text-sm font-medium text-gray-700" for="meta_description">Meta opis</label>
                        <span id="meta_desc_count" class="text-xs text-gray-400">0/155</span>
                    </div>
                    <textarea name="meta_description"
                              id="meta_description"
                              maxlength="155"
                              rows="3"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
                              placeholder="Meta opis (do 155 znakova)">{{ old('meta_description', $post->meta_description) }}</textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5" for="keyword">Keyword</label>
                    <input type="text"
                           name="keyword"
                           id="keyword"
                           value="{{ old('keyword', $post->keyword) }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           placeholder="npr. football, Premier League">
                </div>
            </div>

        </div>{{-- /sidebar --}}
    </div>{{-- /grid --}}
</form>

{{-- SimpleMDE JS --}}
<script src="https://cdn.jsdelivr.net/npm/simplemde/dist/simplemde.min.js"></script>
<script>
(function () {
    'use strict';

    // ── 1. SimpleMDE ──────────────────────────────────────────────────────────
    var simplemde = new SimpleMDE({
        element: document.getElementById('content'),
        spellChecker: false,
        autosave: { enabled: false },
        toolbar: [
            'bold', 'italic', 'heading', '|',
            'quote', 'unordered-list', 'ordered-list', '|',
            'link', 'image', '|',
            'preview', 'side-by-side', 'fullscreen', '|',
            'guide'
        ]
    });

    // ── 2. Slug generator ─────────────────────────────────────────────────────
    function slugify(text) {
        var map = { 'č':'c','ć':'c','š':'s','ž':'z','đ':'d',
                    'Č':'c','Ć':'c','Š':'s','Ž':'z','Đ':'d' };
        return text.toLowerCase()
            .replace(/[čćšžđČĆŠŽĐ]/g, function(m) { return map[m]; })
            .replace(/[^a-z0-9\s-]/g, '')
            .trim()
            .replace(/[\s_-]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    var slugManuallyEdited = {{ $post->exists ? 'true' : 'false' }};

    document.getElementById('title').addEventListener('input', function () {
        if (!slugManuallyEdited) {
            document.getElementById('slug').value = slugify(this.value);
        }
    });

    document.getElementById('slug').addEventListener('input', function () {
        slugManuallyEdited = true;
    });

    // ── 3. Published toggle ───────────────────────────────────────────────────
    var publishedInput = document.getElementById('published_input');
    var toggleBg       = document.getElementById('toggle-bg');
    var toggleDot      = document.getElementById('toggle-dot');
    var toggleText     = document.getElementById('toggle-label-text');

    function setToggle(on) {
        publishedInput.value = on ? '1' : '0';
        if (on) {
            toggleBg.classList.remove('bg-gray-300');
            toggleBg.classList.add('bg-blue-600');
            toggleDot.classList.add('translate-x-5');
            toggleText.textContent = 'Objavljeno';
        } else {
            toggleBg.classList.remove('bg-blue-600');
            toggleBg.classList.add('bg-gray-300');
            toggleDot.classList.remove('translate-x-5');
            toggleText.textContent = 'Nacrt';
        }
    }

    document.getElementById('toggle-wrap').addEventListener('click', function () {
        setToggle(publishedInput.value !== '1');
    });

    // ── 4. Submit buttons ─────────────────────────────────────────────────────
    document.getElementById('btn-publish').addEventListener('click', function () {
        setToggle(true);
        document.getElementById('postForm').submit();
    });

    document.getElementById('btn-draft').addEventListener('click', function () {
        setToggle(false);
        document.getElementById('postForm').submit();
    });

    // ── 5. Image preview ──────────────────────────────────────────────────────
    function showPreview(file) {
        if (!file || !file.type.startsWith('image/')) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            document.getElementById('preview-img').src = e.target.result;
            document.getElementById('preview-wrapper').classList.remove('hidden');
            document.getElementById('upload-icon').classList.add('hidden');
            document.getElementById('upload-text').textContent = file.name;
        };
        reader.readAsDataURL(file);
    }

    document.getElementById('featured_image_input').addEventListener('change', function () {
        if (this.files && this.files[0]) {
            showPreview(this.files[0]);
        }
    });

    // ── 6. Drag & drop ────────────────────────────────────────────────────────
    var uploadArea = document.getElementById('upload-area');

    uploadArea.addEventListener('dragover', function (e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.add('border-blue-400', 'bg-blue-50');
    });

    uploadArea.addEventListener('dragleave', function (e) {
        e.stopPropagation();
        uploadArea.classList.remove('border-blue-400', 'bg-blue-50');
    });

    uploadArea.addEventListener('drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.remove('border-blue-400', 'bg-blue-50');
        var file = e.dataTransfer.files[0];
        if (file && file.type.startsWith('image/')) {
            var dt = new DataTransfer();
            dt.items.add(file);
            document.getElementById('featured_image_input').files = dt.files;
            showPreview(file);
        }
    });

    // ── 7. Character counters ─────────────────────────────────────────────────
    function charCounter(inputId, counterId, max) {
        var el  = document.getElementById(inputId);
        var cnt = document.getElementById(counterId);
        if (!el || !cnt) return;
        function update() {
            var len = el.value.length;
            cnt.textContent = len + '/' + max;
            if (len > max * 0.9) {
                cnt.className = 'text-xs text-red-500';
            } else if (len > max * 0.7) {
                cnt.className = 'text-xs text-yellow-500';
            } else {
                cnt.className = 'text-xs text-gray-400';
            }
        }
        el.addEventListener('input', update);
        update();
    }

    charCounter('meta_title',       'meta_title_count', 60);
    charCounter('meta_description', 'meta_desc_count',  155);

}());
</script>
@endsection
