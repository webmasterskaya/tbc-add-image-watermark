<?php

namespace Webmasterskaya\TelegramBotCommands\Commands\UserCommands;

use Intervention\Image\ImageManagerStatic as Image;
use Longman\TelegramBot\Commands as BotCommands;
use Longman\TelegramBot\Entities\File;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InputMedia\InputMediaPhoto;
use Longman\TelegramBot\Entities\PhotoSize;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class AddWatermarkCallbackqueryCommand extends BotCommands\UserCommand
{

	/**
	 * @var string
	 */
	protected $name = 'addwatermark_callbackquery';

	/**
	 * @var string
	 */
	protected $description = 'add watermark callback handler';

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
		$callback_query = $this->getCallbackQuery();

		$message          = $callback_query->getMessage();
		$user             = $callback_query->getFrom();
		$data             = $callback_query->getData();
		$chat             = $message->getChat();
		$original_message = $message->getReplyToMessage();

		$data = explode('_', $data);

		if ($original_message)
		{
			if ($photoSizes = $original_message->getPhoto())
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

							$src       = Image::configure(['driver' => 'Imagick'])->make($file_path);
							$watermark = Image::configure(['driver' => 'Imagick'])->make($watermark_path);

							$opacity = (int) $data[array_key_last($data)];

							$opacity      = ($opacity <= 0) ? 0 : (($opacity >= 100) ? 100 : $opacity);

							$opacity_next = $opacity + 5;
							$opacity_next = ($opacity_next <= 0) ? 5 : (($opacity_next >= 100) ? 100 : $opacity_next);

							$opacity_prev = $opacity - 5;
							$opacity_prev = ($opacity_prev <= 0) ? 0 : (($opacity_prev >= 100) ? 95 : $opacity_prev);

							$a     = $src->width(); // Ширина картинки
							$b     = $src->height(); // Высота картинки
							$x     = $watermark->width(); // Исходная ширина вотермарки
							$y     = $watermark->height(); // Исходная высота вотермарки
							$tan   = $b / $a;
							$angle = rad2deg(atan($tan)); // Угол наклона вотермарки относительно горизонтальной оси в градусах

							$c = $b / (sin(deg2rad($angle)) + (cos(deg2rad($angle)) * $y / $x)); // Новая ширина вотермарки
							$d = $c * $y / $x; // Новая высота вотермарки

							$watermark->resize($c, $d, function ($constraint) {
								$constraint->aspectRatio();
								$constraint->upsize();
							});

							$watermark->rotate($angle);

							$watermark->opacity($opacity);

							$src->insert($watermark, 'center');

							$src->save();

							$edit_photo_request = Request::editMessageMedia([
								'chat_id'      => $message->getChat()->getId(),
								'message_id'   => $message->getMessageId(),
								'media'        => new InputMediaPhoto(['media' => Request::encodeFile($file_path)]),
								'reply_markup' => new InlineKeyboard(
									[
										[
											'text'          => '-',
											'callback_data' => 'addwatermark_callbackquery_opacity_' . $opacity_prev
										],
										[
											'text'          => 'Прозрачность: ' . $opacity,
											'callback_data' => 'do_nothing'
										],
										[
											'text'          => '+',
											'callback_data' => 'addwatermark_callbackquery_opacity_' . $opacity_next
										],
									]
								)
							]);

							if ($edit_photo_request->isOk())
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