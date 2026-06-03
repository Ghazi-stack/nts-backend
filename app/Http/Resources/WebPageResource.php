<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebPageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'pageName' => $this->page_name,
            'pageUrl' => $this->page_url,
            'pageTitle' => $this->page_title,
            'pageContent' => $this->page_content,
            'pageType' => $this->page_type,
            'templateLayoutFile' => $this->template_layout_file,
            'metaKeywords' => $this->meta_keywords,
            'metaDescription' => $this->meta_description,
            'searchKeywords' => $this->search_keywords,
            'displayAsHomePage' => (bool) $this->display_as_home_page,
            'restrictToCustomersOnly' => (bool) $this->restrict_to_customers_only,
            'showInNavigation' => (bool) $this->show_in_navigation,
            'sortOrder' => $this->sort_order,
            'parentPage' => $this->parent_page,
            'storeId' => $this->store_id,
            'link' => $this->link,
            'emailQuestionsTo' => $this->emailQuestionsTo,
            'showTheseFields' => $this->showTheseFields,
            'rawHtml' => $this->rawHtml,
            'slugWithUrl' => $this->slugWithUrl,
        ];
    }
}
