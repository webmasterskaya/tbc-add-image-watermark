<?php

namespace Webmasterskaya\TelegramBotCommands\Commands\UserCommands;

use Intervention\Image\ImageManagerStatic as Image;
use Longman\TelegramBot\Commands as BotCommands;
use Longman\TelegramBot\Entities\File;
use Longman\TelegramBot\Entities\PhotoSize;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class AddWatermarkCommand extends BotCommands\UserCommand
{

	/**
	 * @var string
	 */
	protected $name = 'addwatermark';

	/**
	 * @var string
	 */
	protected $description = 'add watermark';

	/**
	 * @var string
	 */
	protected $usage = '/addwatermark';

	/**
	 * @var string
	 */
	protected $version = '1.0.0';

	/**
	 * @var bool
	 */
	protected $private_only = true;

	protected function checkConfig()
	{
		if (empty($this->config['watermark_path']) || !file_exists($this->config['watermark_path']))
		{
			throw new \Exception('Invalid command config! Required parameter `watermark_path` not set or file not exists.');
		}
	}

	public function preExecute(): ServerResponse
	{
		$this->checkConfig();

		return parent::preExecute(); // TODO: Change the autogenerated stub
	}

	public function execute(): ServerResponse
	{
		if ($message = $this->getMessage() ?: $this->getEditedMessage() ?: $this->getChannelPost() ?: $this->getEditedChannelPost())
		{
			if ($photoSizes = $message->getPhoto())
			{
				/** @var PhotoSize $photo */
				$photo = end($photoSizes);

				if ($photo)
				{
					$file_id = $photo->getFileId();

					$file_response = Request::getFile(['file_id' => $file_id]);

					if ($file_response->isOk())
					{
						/** @var File $file */
						$file = $file_response->getResult();

						if (Request::downloadFile($file))
						{
							$download_path  = $this->telegram->getDownloadPath();
							$tg_file_path   = $file->getFilePath();
							$file_path      = realpath($download_path . '/' . $tg_file_path);
							$watermark_path = realpath($this->config['watermark_path']);

							$src = Image::make($file_path);
							$watermark = Image::make($watermark_path);

							$watermark->resize($src->width() - 20, $src->height() - 20);

							$src->insert($watermark, 'center');

							$src->save();

							$data = [
								'chat_id'             => $message->getChat()->getId(),
								'photo'               => Request::encodeFile($file_path),
								'caption'             => $message->getCaption(),
								'caption_entities'    => $message->getCaptionEntities(),
								'reply_to_message_id' => $message->getMessageId()
							];

							$send_photo_request = Request::sendPhoto($data);

							if ($send_photo_request->isOk())
							{
								unlink($file_path);
							}
						}
					}
				}
			}
		}

		return Request::EmptyResponse();
	}
}