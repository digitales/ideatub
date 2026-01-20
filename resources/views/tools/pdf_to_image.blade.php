@extends('layouts.app')

@section('title', $metadata['title'])
@section('description', $metadata['description'])
@section('keywords', $metadata['keywords'])

@section('schema')
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "WebApplication",
    "name": "{{ $metadata['name'] }}",
    "description": "{{ $metadata['description'] }}",
    "url": "{{ url()->current() }}",
    "applicationCategory": "UtilityApplication",
    "operatingSystem": "Any",
    "offers": {
        "@type": "Offer",
        "price": "0",
        "priceCurrency": "USD"
    }
}
</script>
@endsection

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="bg-white rounded-lg shadow-sm p-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">{{ $metadata['name'] }}</h1>
        <p class="text-gray-600 mb-8">{{ $metadata['description'] }}</p>

        @include('components.operation-counter')

        <div x-data="pdfToImageConverter()" class="space-y-6">
            <!-- File Upload -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Select PDF file</label>
                <input type="file" @change="handleFile($event.target.files)" accept="application/pdf" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
            </div>

            <!-- Format Selection -->
            <div x-show="file">
                <label class="block text-sm font-medium text-gray-700 mb-2">Output format</label>
                <select x-model="format" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="png">PNG</option>
                    <option value="jpg">JPG</option>
                </select>
            </div>

            <!-- Page Selection -->
            <div x-show="totalPages > 0">
                <label class="block text-sm font-medium text-gray-700 mb-2">Pages to convert</label>
                <select x-model="pageSelection" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="all">All pages</option>
                    <option value="first">First page only</option>
                    <option value="range">Page range</option>
                </select>
                
                <div x-show="pageSelection === 'range'" class="mt-2">
                    <input type="number" x-model="startPage" min="1" :max="totalPages" placeholder="Start page" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <input type="number" x-model="endPage" min="1" :max="totalPages" placeholder="End page" class="block w-full mt-2 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                
                <p class="mt-1 text-sm text-gray-500">Total pages: <span x-text="totalPages"></span></p>
            </div>

            <!-- Process Button -->
            <button @click="convertToImage()" 
                    :disabled="!file || processing"
                    class="w-full bg-indigo-600 text-white py-3 px-4 rounded-lg font-semibold hover:bg-indigo-700 disabled:bg-gray-400 disabled:cursor-not-allowed"
                    x-text="processing ? 'Converting...' : 'Convert to Image'">
            </button>

            <!-- Download Links -->
            <div x-show="downloadLinks.length > 0" class="space-y-2">
                <h3 class="font-semibold text-gray-900">Download images:</h3>
                <template x-for="(link, index) in downloadLinks" :key="index">
                    <a :href="link.url" :download="link.filename" 
                       class="block bg-gray-50 p-3 rounded hover:bg-gray-100 text-sm text-gray-700">
                        <span x-text="link.filename"></span>
                    </a>
                </template>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="{{ asset('js/pdf-tools.js') }}"></script>
<script>
function pdfToImageConverter() {
    return {
        file: null,
        totalPages: 0,
        format: 'png',
        pageSelection: 'all',
        startPage: 1,
        endPage: 1,
        processing: false,
        downloadLinks: [],

        async handleFile(fileList) {
            if (fileList.length === 0) return;
            this.file = fileList[0];
            try {
                const pdfDoc = await PDFLib.PDFDocument.load(await this.file.arrayBuffer());
                this.totalPages = pdfDoc.getPageCount();
                this.endPage = this.totalPages;
            } catch (error) {
                alert('Error loading PDF: ' + error.message);
            }
        },

        async convertToImage() {
            if (!this.file) return;
            
            this.processing = true;
            this.downloadLinks = [];
            
            try {
                let pages = [];
                if (this.pageSelection === 'all') {
                    pages = Array.from({ length: this.totalPages }, (_, i) => i + 1);
                } else if (this.pageSelection === 'first') {
                    pages = [1];
                } else {
                    pages = Array.from({ length: this.endPage - this.startPage + 1 }, (_, i) => this.startPage + i);
                }
                
                const result = await pdfToImage(this.file, pages, this.format);
                this.downloadLinks = result.files.map((f, i) => ({
                    url: f.url,
                    filename: `page-${pages[i]}.${this.format}`
                }));
                
                await this.trackOperation('pdf-to-image');
            } catch (error) {
                alert('Error converting PDF: ' + error.message);
            } finally {
                this.processing = false;
            }
        },

        async trackOperation(type) {
            try {
                const response = await fetch('/operations/track', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ operation_type: type })
                });
                
                if (response.ok) {
                    const data = await response.json();
                    window.dispatchEvent(new CustomEvent('operations-updated', { detail: data.operations_remaining }));
                }
            } catch (error) {
                console.error('Failed to track operation:', error);
            }
        }
    }
}
</script>
@endpush
@endsection
