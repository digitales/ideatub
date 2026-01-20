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

        <div x-data="pdfSplitter()" class="space-y-6">
            <!-- File Upload -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Select PDF file</label>
                <input type="file" @change="handleFile($event.target.files)" accept="application/pdf" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
            </div>

            <!-- Split Options -->
            <div x-show="pdfLoaded" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Split mode</label>
                    <select x-model="splitMode" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="pages">Extract specific pages</option>
                        <option value="chunks">Split into chunks</option>
                    </select>
                </div>

                <!-- Extract Pages -->
                <div x-show="splitMode === 'pages'">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Page numbers (e.g., 1,3,5-7)</label>
                    <input type="text" x-model="pageNumbers" placeholder="1,3,5-7" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <p class="mt-1 text-sm text-gray-500">Total pages: <span x-text="totalPages"></span></p>
                </div>

                <!-- Split into Chunks -->
                <div x-show="splitMode === 'chunks'">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Pages per file</label>
                    <input type="number" x-model="pagesPerChunk" min="1" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <button @click="splitPdf()" 
                        :disabled="processing"
                        class="w-full bg-indigo-600 text-white py-3 px-4 rounded-lg font-semibold hover:bg-indigo-700 disabled:bg-gray-400 disabled:cursor-not-allowed"
                        x-text="processing ? 'Processing...' : 'Split PDF'">
                </button>
            </div>

            <!-- Download Links -->
            <div x-show="downloadLinks.length > 0" class="space-y-2">
                <h3 class="font-semibold text-gray-900">Download split files:</h3>
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
function pdfSplitter() {
    return {
        file: null,
        pdfLoaded: false,
        totalPages: 0,
        splitMode: 'pages',
        pageNumbers: '',
        pagesPerChunk: 2,
        processing: false,
        downloadLinks: [],

        async handleFile(fileList) {
            if (fileList.length === 0) return;
            this.file = fileList[0];
            try {
                const pdfDoc = await PDFLib.PDFDocument.load(await this.file.arrayBuffer());
                this.totalPages = pdfDoc.getPageCount();
                this.pdfLoaded = true;
            } catch (error) {
                alert('Error loading PDF: ' + error.message);
            }
        },

        async splitPdf() {
            if (!this.file) return;
            
            this.processing = true;
            this.downloadLinks = [];
            
            try {
                if (this.splitMode === 'pages') {
                    const result = await splitPdf(this.file, this.pageNumbers);
                    this.downloadLinks = result.files.map((f, i) => ({
                        url: f.url,
                        filename: `split-page-${i + 1}.pdf`
                    }));
                } else {
                    const result = await splitPdfIntoChunks(this.file, this.pagesPerChunk);
                    this.downloadLinks = result.files.map((f, i) => ({
                        url: f.url,
                        filename: `chunk-${i + 1}.pdf`
                    }));
                }
                
                await this.trackOperation('split');
            } catch (error) {
                alert('Error splitting PDF: ' + error.message);
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
